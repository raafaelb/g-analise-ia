<?php

namespace Tests\Feature;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\CodexProtocol;
use App\Services\AttachmentContext;
use App\Services\PdfAttachmentPages;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class AttachmentContextTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->root = storage_path('framework/testing/attachments-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function attachment(string $name, string $content, ?Project $project = null): Artifact
    {
        $project ??= Project::create(['user_id' => User::factory()->create()->id, 'name' => 'Anexos']);
        $path = 'uploads/'.$project->user_id.'/'.$project->id.'/'.Str::uuid();
        Storage::disk('local')->put($path, $content);

        return Artifact::create(['user_id' => $project->user_id, 'project_id' => $project->id, 'name' => $name, 'kind' => 'attachment', 'mime' => 'application/octet-stream', 'path' => $path, 'metadata' => ['extraction' => ['status' => 'queued']]]);
    }

    public function test_csv_is_available_to_the_correct_project_only_and_original_is_preserved(): void
    {
        $artifact = $this->attachment('vendas.csv', "mes,total\nJaneiro,450\n");
        $project = Project::findOrFail($artifact->project_id);
        $inputs = app(AttachmentContext::class)->inputs($project);
        $this->assertStringContainsString('Janeiro,450', $inputs[0]['text']);
        $this->assertStringContainsString('NÃO CONFIÁVEL', $inputs[0]['text']);
        $this->assertSame('ready', $artifact->fresh()->metadata['extraction']['status']);
        $this->assertSame("mes,total\nJaneiro,450\n", Storage::disk('local')->get($artifact->path));
        $other = Project::create(['user_id' => $project->user_id, 'name' => 'Outro']);
        $this->assertSame([], app(AttachmentContext::class)->inputs($other));
        $this->assertSame($inputs, CodexProtocol::turn('thread', 'pergunta', $inputs)['input'] === [] ? [] : array_slice(CodexProtocol::turn('thread', 'pergunta', $inputs)['input'], 0, -1));
    }

    public function test_large_text_is_bounded_and_explicitly_marked_as_partial(): void
    {
        $artifact = $this->attachment('grande.txt', str_repeat('exemplo ', 30000));
        app(AttachmentContext::class)->extract($artifact);
        $state = $artifact->fresh()->metadata['extraction'];
        $this->assertTrue($state['truncated']);
        $this->assertLessThanOrEqual(180000, $state['characters']);
        $inputs = app(AttachmentContext::class)->inputs(Project::find($artifact->project_id));
        $this->assertLessThan(14000, strlen($inputs[0]['text']));
    }

    public function test_invalid_image_is_failed_without_pretending_it_was_read(): void
    {
        $artifact = $this->attachment('imagem.png', 'not a png');
        app(AttachmentContext::class)->extract($artifact);
        $this->assertSame('failed', $artifact->fresh()->metadata['extraction']['status']);
        $inputs = app(AttachmentContext::class)->inputs(Project::find($artifact->project_id));
        $this->assertCount(1, $inputs);
        $this->assertStringContainsString('Imagem inválida', $inputs[0]['text']);
    }

    public function test_real_image_becomes_native_multimodal_input(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=');
        $artifact = $this->attachment('imagem.png', $png);
        $inputs = app(AttachmentContext::class)->inputs(Project::find($artifact->project_id));
        $this->assertCount(2, $inputs);
        $this->assertSame('localImage', $inputs[1]['type']);
        $this->assertFileExists($inputs[1]['path']);
    }

    private function imageOnlyPdf(int $pageCount = 1): string
    {
        $pageIds = array_map(fn (int $index): string => (4 + $index * 2).' 0 R', range(0, $pageCount - 1));
        $pixels = '1661ED1661EDFFFFFF1661ED>';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', $pageIds).'] /Count '.$pageCount.' >>',
            '<< /Type /XObject /Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /ASCIIHexDecode /Length '.strlen($pixels).' >>'."\nstream\n".$pixels."\nendstream",
        ];
        for ($index = 0; $index < $pageCount; $index++) {
            $stream = 'q 200 0 0 150 20 20 cm /Scan Do Q';
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 240 190] /Resources << /XObject << /Scan 3 0 R >> >> /Contents '.(5 + $index * 2).' 0 R >>';
            $objects[] = '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream";
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    public function test_an_image_only_pdf_becomes_a_verified_native_visual_input(): void
    {
        $pdf = $this->imageOnlyPdf();
        $artifact = $this->attachment('documento-digitalizado.pdf', $pdf);
        $project = Project::findOrFail($artifact->project_id);

        $inputs = app(AttachmentContext::class)->inputs($project);

        $state = $artifact->fresh()->metadata['extraction'];
        $this->assertSame('ready', $state['status']);
        $this->assertTrue($state['visual_pdf']);
        $this->assertCount(1, $state['visual_pages']);
        $this->assertCount(2, $inputs);
        $this->assertSame('localImage', $inputs[1]['type']);
        $this->assertFileExists($inputs[1]['path']);
        $size = getimagesize($inputs[1]['path']);
        $this->assertSame('image/png', $size['mime']);
        $this->assertLessThanOrEqual(1600, max($size[0], $size[1]));
        $manifest = json_decode(Str::after($inputs[0]['text'], "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['page' => 1, 'image_index' => 1]], $manifest['attachments'][0]['visual_pages']);
        $this->assertSame($pdf, Storage::disk('local')->get($artifact->path));
    }

    public function test_a_long_image_only_pdf_includes_only_its_first_three_pages_and_discloses_the_limit(): void
    {
        $artifact = $this->attachment('digitalizado-longo.pdf', $this->imageOnlyPdf(4));

        $inputs = app(AttachmentContext::class)->inputs(Project::findOrFail($artifact->project_id));

        $state = $artifact->fresh()->metadata['extraction'];
        $this->assertSame('ready', $state['status']);
        $this->assertCount(3, $state['visual_pages']);
        $this->assertTrue($state['truncated']);
        $this->assertCount(4, $inputs);
        $manifest = json_decode(Str::after($inputs[0]['text'], "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([1, 2, 3], array_column($manifest['attachments'][0]['visual_pages'], 'page'));
        $this->assertStringContainsString('3', $manifest['attachments'][0]['message']);
        $this->assertStringContainsString('Não alegue leitura integral', $inputs[0]['text']);
    }

    public function test_a_previously_empty_pdf_is_prepared_visually_without_uploading_it_again(): void
    {
        $artifact = $this->attachment('digitalizado-anterior.pdf', $this->imageOnlyPdf());
        $artifact->update(['metadata' => ['extraction' => ['status' => 'empty', 'message' => 'Sem texto extraível.']]]);

        $inputs = app(AttachmentContext::class)->inputs(Project::findOrFail($artifact->project_id));

        $this->assertSame('ready', $artifact->fresh()->metadata['extraction']['status']);
        $this->assertCount(2, $inputs);
        $this->assertSame('localImage', $inputs[1]['type']);
    }

    public function test_pdf_pages_share_the_visual_budget_with_uploaded_images_and_keep_their_input_indices(): void
    {
        $this->freezeTime();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=');
        $first = $this->attachment('referencia-1.png', $png);
        $project = Project::findOrFail($first->project_id);
        $this->travel(1)->seconds();
        $this->attachment('referencia-2.png', $png, $project);
        $this->travel(1)->seconds();
        $this->attachment('digitalizado.pdf', $this->imageOnlyPdf(3), $project);

        $inputs = app(AttachmentContext::class)->inputs($project);

        $this->assertCount(4, $inputs);
        $manifest = json_decode(Str::after($inputs[0]['text'], "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $manifest['attachments'][0]['image_index']);
        $this->assertSame(2, $manifest['attachments'][1]['image_index']);
        $this->assertSame([['page' => 1, 'image_index' => 3]], $manifest['attachments'][2]['visual_pages']);
        $this->assertSame(2, $manifest['attachments'][2]['visual_pages_omitted']);
        $this->assertStringContainsString('não foram incluídas', $manifest['attachments'][2]['message']);
    }

    public function test_a_pdf_with_no_remaining_visual_budget_does_not_claim_its_pages_were_included(): void
    {
        $this->freezeTime();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=');
        $first = $this->attachment('referencia-1.png', $png);
        $project = Project::findOrFail($first->project_id);
        for ($number = 2; $number <= 3; $number++) {
            $this->travel(1)->seconds();
            $this->attachment('referencia-'.$number.'.png', $png, $project);
        }
        $this->travel(1)->seconds();
        $this->attachment('digitalizado.pdf', $this->imageOnlyPdf(), $project);

        $inputs = app(AttachmentContext::class)->inputs($project);

        $this->assertCount(4, $inputs);
        $manifest = json_decode(Str::after($inputs[0]['text'], "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $manifest['attachments'][3]['visual_pages']);
        $this->assertSame(1, $manifest['attachments'][3]['visual_pages_omitted']);
        $this->assertStringContainsString('não foram incluídas', $manifest['attachments'][3]['message']);
    }

    public function test_a_failed_pdf_renderer_is_reported_without_attaching_an_image_or_changing_the_original(): void
    {
        $pdf = $this->imageOnlyPdf();
        $artifact = $this->attachment('digitalizado.pdf', $pdf);
        $this->mock(PdfAttachmentPages::class)->shouldReceive('render')->once()->andThrow(new \RuntimeException('Não foi possível preparar as páginas deste PDF.'));

        $inputs = app(AttachmentContext::class)->inputs(Project::findOrFail($artifact->project_id));

        $this->assertCount(1, $inputs);
        $this->assertSame('failed', $artifact->fresh()->metadata['extraction']['status']);
        $this->assertStringContainsString('Não foi possível preparar as páginas', $inputs[0]['text']);
        $this->assertSame($pdf, Storage::disk('local')->get($artifact->path));
    }

    public function test_a_pdf_timeout_has_an_actionable_message_without_internal_command_paths(): void
    {
        $artifact = $this->attachment('digitalizado.pdf', $this->imageOnlyPdf());
        $process = new Process(['/usr/bin/pdftoppm', '/synthetic-private-document.pdf']);
        $process->setTimeout(30);
        $this->mock(PdfAttachmentPages::class)->shouldReceive('render')->once()->andThrow(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $inputs = app(AttachmentContext::class)->inputs(Project::findOrFail($artifact->project_id));

        $this->assertSame('failed', $artifact->fresh()->metadata['extraction']['status']);
        $this->assertStringContainsString('Envie um arquivo menor ou separe as páginas necessárias.', $inputs[0]['text']);
        $this->assertStringNotContainsString('pdftoppm', $inputs[0]['text']);
        $this->assertStringNotContainsString('/synthetic-private-document.pdf', $inputs[0]['text']);
        $this->assertCount(1, $inputs);
    }

    public function test_a_modified_pdf_page_does_not_reach_the_model(): void
    {
        $artifact = $this->attachment('digitalizado.pdf', $this->imageOnlyPdf());
        $project = Project::findOrFail($artifact->project_id);
        $context = app(AttachmentContext::class);
        $inputs = $context->inputs($project);
        File::append($inputs[1]['path'], 'conteudo alterado');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('páginas de um anexo PDF foram alteradas');
        $context->inputs($project);
    }

    public function test_a_missing_pdf_page_is_reported_before_sending_a_nonexistent_path(): void
    {
        $artifact = $this->attachment('digitalizado.pdf', $this->imageOnlyPdf());
        $project = Project::findOrFail($artifact->project_id);
        $context = app(AttachmentContext::class);
        $inputs = $context->inputs($project);
        File::delete($inputs[1]['path']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('página visual do PDF está indisponível');
        $context->inputs($project);
    }

    public function test_a_pdf_page_path_cannot_reference_another_attachment(): void
    {
        $artifact = $this->attachment('digitalizado.pdf', $this->imageOnlyPdf());
        $project = Project::findOrFail($artifact->project_id);
        $context = app(AttachmentContext::class);
        $context->inputs($project);
        $metadata = $artifact->fresh()->metadata;
        $metadata['extraction']['visual_pages'][0]['path'] = 'attachments/outro-anexo/page-1.png';
        $artifact->update(['metadata' => $metadata]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('páginas de um anexo PDF foram alteradas');
        $context->inputs($project);
    }

    public function test_a_pdf_prepared_in_the_background_is_available_after_a_minute_without_being_rendered_again(): void
    {
        $this->freezeTime();
        $artifact = $this->attachment('digitalizado.pdf', $this->imageOnlyPdf());
        $project = Project::findOrFail($artifact->project_id);
        $context = app(AttachmentContext::class);
        $expected = $context->inputs($project);
        $ready = $artifact->fresh()->metadata;
        $processing = $ready;
        $processing['extraction']['status'] = 'processing';
        $artifact->update(['metadata' => $processing]);
        $this->partialMock(PdfAttachmentPages::class)->shouldReceive('render')->never();
        $lock = Mockery::mock();
        $lock->shouldReceive('block')->once()->andReturnUsing(function (int $seconds, \Closure $callback) use ($artifact, $ready): void {
            if ($seconds < 60) {
                throw new LockTimeoutException;
            }
            $this->travel(60)->seconds();
            $artifact->update(['metadata' => $ready]);
            $callback();
        });
        Cache::partialMock()->shouldReceive('lock')->once()->andReturn($lock);

        $inputs = $context->inputs($project);

        $this->assertSame($expected, $inputs);
        $this->assertSame('ready', $artifact->fresh()->metadata['extraction']['status']);
    }

    public function test_a_busy_attachment_returns_a_clear_wait_message_without_overwriting_the_other_preparation(): void
    {
        $artifact = $this->attachment('dados.csv', "mes,total\nJaneiro,450\n");
        $artifact->update(['metadata' => ['extraction' => ['status' => 'processing']]]);
        $lock = Mockery::mock();
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        Cache::partialMock()->shouldReceive('lock')->once()->andReturn($lock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Um anexo ainda está sendo preparado. Aguarde o processamento e envie a mensagem novamente.');
        try {
            app(AttachmentContext::class)->inputs(Project::findOrFail($artifact->project_id));
        } finally {
            $this->assertSame('processing', $artifact->fresh()->metadata['extraction']['status']);
            $this->assertSame("mes,total\nJaneiro,450\n", Storage::disk('local')->get($artifact->path));
        }
    }

    public function test_pdf_text_is_extracted_by_the_real_local_reader(): void
    {
        $stream = 'BT /F1 12 Tf 40 150 Td (Dashboard teste 450) Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }$xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        $artifact = $this->attachment('teste.pdf', $pdf);
        $inputs = app(AttachmentContext::class)->inputs(Project::find($artifact->project_id));
        $this->assertSame('ready', $artifact->fresh()->metadata['extraction']['status']);
        $this->assertStringContainsString('Dashboard teste 450', $inputs[0]['text']);
        $this->assertTrue($artifact->fresh()->metadata['extraction']['truncated']);
    }

    private function workbook(string $sheet): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($temp, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Vendas" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Janeiro</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        try {
            return file_get_contents($temp);
        } finally {
            unlink($temp);
        }
    }

    public function test_xlsx_extracts_sheets_cached_values_and_never_evaluates_formula(): void
    {
        $file = $this->workbook('<worksheet><sheetData><row><c r="A1" t="s"><v>0</v></c><c r="B1"><f>WEBSERVICE("https://example.invalid")</f><v>450</v></c><c r="C1"><f>1+2</f></c></row></sheetData></worksheet>');
        $artifact = $this->attachment('vendas.xlsx', $file);
        $inputs = app(AttachmentContext::class)->inputs(Project::find($artifact->project_id));
        $this->assertSame('ready', $artifact->fresh()->metadata['extraction']['status']);
        $this->assertStringContainsString('ABA: Vendas', $inputs[0]['text']);
        $this->assertStringContainsString('A1=Janeiro', $inputs[0]['text']);
        $this->assertStringContainsString('B1=450', $inputs[0]['text']);
        $this->assertStringContainsString('fórmula sem valor armazenado', $inputs[0]['text']);
        $this->assertStringNotContainsString('WEBSERVICE', $inputs[0]['text']);
    }

    public function test_xlsx_xml_entities_are_rejected(): void
    {
        $artifact = $this->attachment('unsafe.xlsx', $this->workbook('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><worksheet><sheetData><row><c r="A1">&e;</c></row></sheetData></worksheet>'));
        app(AttachmentContext::class)->extract($artifact);
        $this->assertSame('failed', $artifact->fresh()->metadata['extraction']['status']);
        $this->assertStringContainsString('XML não permitidas', $artifact->fresh()->metadata['extraction']['message']);
    }

    public function test_original_from_another_project_cannot_be_included(): void
    {
        $artifact = $this->attachment('privado.txt', 'private');
        $artifact->update(['path' => 'uploads/999/other/private.txt']);
        app(AttachmentContext::class)->extract($artifact);
        $this->assertSame('failed', $artifact->fresh()->metadata['extraction']['status']);
    }

    public function test_derived_text_tampering_does_not_reach_the_model(): void
    {
        $artifact = $this->attachment('dados.txt', 'dados originais');
        $context = app(AttachmentContext::class);
        $context->extract($artifact);
        File::put($this->root.'/'.$artifact->user_id.'/'.$artifact->project_id.'/attachments/'.$artifact->id.'.txt', 'alterado');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contexto de um anexo foi alterado');
        $context->inputs(Project::find($artifact->project_id));
    }
}
