<?php

namespace App\Console\Commands;

use App\Services\DeploymentReadiness;
use Illuminate\Console\Command;

class CheckDeploymentReadiness extends Command
{
    protected $signature = 'ganalise:preflight {--runtime : Confere também banco, migrações, arquivos e workers locais}';

    protected $description = 'Confere a instalação central sem chamar IA, alterar o BI ou exibir segredos.';

    public function handle(DeploymentReadiness $readiness): int
    {
        $report = $readiness->inspect((bool) $this->option('runtime'));
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
