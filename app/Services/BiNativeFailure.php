<?php

namespace App\Services;

use RuntimeException;

class BiNativeFailure extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message, public readonly array $details = [], int $exitCode = 9)
    {
        parent::__construct($message, $exitCode);
    }

    public static function appQuerySuspended(): self
    {
        return new self('app_query_suspended', 'A consulta direta de valores está temporariamente indisponível para preservar suas permissões de dados. Use Conferir dados ou a prévia do dashboard. Sua conexão BI continua válida.');
    }

    public static function detect(array $arguments, int $exitCode, string $output, string $stderr): ?self
    {
        $failure = mb_substr($stderr, 0, 12_000)."\n".mb_substr($output, 0, 4_000);
        if ($exitCode === 6 && ($arguments[0] ?? '') === 'list' && ($arguments[1] ?? '') === 'investigation' && trim($failure) === 'Error: list investigation: A Sentinela não está disponível para este ambiente.') {
            return new self('module_unavailable', 'Investigações não estão disponíveis neste ambiente. Consulte a habilitação do módulo Sentinela com o administrador do G-Analise BI.', ['module' => 'sentinela'], 6);
        }
        if ($exitCode === 10 && ($arguments[0] ?? '') === 'app' && ($arguments[1] ?? '') === 'query' && preg_match('/^app:([1-9][0-9]*)$/D', $arguments[2] ?? '', $reference) && preg_match('/\AError: query '.preg_quote($arguments[2], '/').': User ([1-9][0-9]{0,14}) has no access to app '.$reference[1].'\z/u', trim($failure), $actor)) {
            return new self('app_query_access_denied', 'O serviço de consulta do G-Analise BI recusou acesso a esta aplicação. Confira com o administrador do BI as permissões e a identidade usada pela integração de consulta.', ['resource' => $arguments[2], 'native_actor_id' => (int) $actor[1]], 10);
        }
        if ($exitCode !== 9) {
            return null;
        }
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
