<?php

namespace Tests\Feature;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\CodexProtocol;
use App\Services\AttachmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
