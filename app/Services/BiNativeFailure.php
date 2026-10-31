<?php

namespace App\Services;

use RuntimeException;

class BiNativeFailure extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message, 9);
    }

    public static function detect(array $arguments, int $exitCode, string $output, string $stderr): ?self
    {
        if ($exitCode !== 9) {
            return null;
        }
        $failure = mb_substr($stderr, 0, 12_000)."\n".mb_substr($output, 0, 4_000);
        if (($arguments[0] ?? '') === 'flow' && ($arguments[1] ?? '') === 'status' && preg_match('/^flow:([1-9][0-9]*)$/D', $arguments[2] ?? '', $reference) && preg_match('/\AError: status '.preg_quote($arguments[2], '/').': Flow '.$reference[1].' ainda não tem execução registrada\.\z/u', trim($failure))) {
            return new self('flow_no_execution', 'Esta fonte ainda não possui uma execução registrada.', ['resource' => $arguments[2]]);
        }
        $imports = (($arguments[0] ?? '') === 'new' && ($arguments[1] ?? '') === 'writeback' && in_array('--from-file', $arguments, true)) || (($arguments[0] ?? '') === 'writeback' && ($arguments[1] ?? '') === 'import');
        if ($imports && preg_match('/Limite de linhas dos Cadastros atingido \((\d{1,15})\/(\d{1,15})\).*?precisa de (\d{1,15}) linhas; margem disponível: (\d{1,15}) linhas/isu', $failure, $match)) {
            $values = array_map(intval(...), array_slice($match, 1));

            return new self('writeback_capacity', 'O BI recusou a importação por falta de capacidade em Cadastros. Registros usados: '.$values[0].' de '.$values[1].'; solicitados: '.$values[2].'; disponíveis: '.$values[3].'. Confira a capacidade com o administrador do BI antes de preparar outra tentativa.', array_combine(['used', 'limit', 'requested', 'available'], $values));
        }
        if (($arguments[0] ?? '') === 'writeback' && ($arguments[1] ?? '') === 'export' && preg_match('/^writeback:[1-9][0-9]*$/D', $arguments[2] ?? '') && preg_match('/download export for '.preg_quote($arguments[2], '/').' failed: 403 forbidden\b/i', $failure)) {
            return new self('writeback_export_forbidden', 'O BI recusou o download desta planilha. Confirme com o administrador do BI a permissão de exportar arquivos deste cadastro.');
        }

        return null;
    }
}
