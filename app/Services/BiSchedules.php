<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiSchedules
{
    public const ACTIONS = BiScheduleDefinition::ACTIONS;

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy, private BiScheduleDefinition $definition) {}

    public function options(User $user, string $tenant, ?string $id = null): array
    {
        [$connection, $environment] = $this->access($user, $tenant);
        abort_unless($id === null || preg_match('/^[1-9][0-9]{0,17}$/D', $id), 422, 'Agenda inválida.');
        $generation = $connection->metadata['session_generation'] ?? null;

        return $this->withContext($connection, $environment, function (Project $context) use ($connection, $id, $generation): array {
            $inventory = $this->inventory($connection, $context, true);
            $source = null;
            if ($id !== null) {
                abort_unless(isset($inventory['schedules'][$id]), 404, 'Esta agenda não está disponível neste ambiente para sua sessão.');
                [, $source] = $this->document($context, 'schedule:'.$id);
            }
            $this->assertGeneration($connection, $generation);

            return ['resource' => $id === null ? null : 'schedule:'.$id, 'version' => $source === null ? null : $this->definition->version($source), 'configuration' => $source === null ? null : $this->form($source), 'agents' => array_values($inventory['agents']), 'flows' => array_values($inventory['flows']), 'credentials' => $inventory['credentials'], 'credentials_available' => $inventory['credentials_available'], 'credentials_message' => $inventory['credentials_message'], 'timezone' => 'America/Sao_Paulo', 'default_anchor' => now('America/Sao_Paulo')->subDay()->startOfDay()->toIso8601String()];
        });
    }

    public function prepare(User $user, string $tenant, array $input): array
    {
        $data = $this->definition->normalize($input);
        [$connection, $environment] = $this->access($user, $tenant);
        $this->policy->assertCommandAllowed($tenant, [$data['action'] === 'create_schedule' ? 'new' : 'push', $data['resource'] ?? 'schedule'], $connection);
        $requestHash = $this->definition->digest([$tenant, $data]);
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $tenant, $connection, $environment, $data, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outra configuração. Abra outra revisão.');

                    return ['approval' => $existing, 'project' => null, 'reused' => true];
                }
                $revision = $this->withContext($connection, $environment, fn (Project $context): array => $this->revision($connection, $context, $data));
                $approval = DB::transaction(function () use ($user, $tenant, $connection, $data, $revision, $requestHash): Approval {
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'agent_run_id' => null, 'type' => 'manual', 'title' => $data['action'] === 'create_schedule' ? 'Criar agenda de atualização' : 'Editar agenda de atualização', 'description' => 'Confira o ambiente, agente, fontes, ordem e horários antes de salvar. Uma agenda ativa pode iniciar cargas automaticamente.', 'payload' => ['origin' => 'manual', 'action' => $data, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $tenant, $data, $revision), 'expires_at' => now()->addMinutes(12)]);
                    Audit::record($user->id, 'bi.schedule.review_created', 'Configuração de agenda preparada para revisão.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'action' => $data['action']]);

                    return $approval;
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return ['approval' => $approval->refresh(), 'project' => null, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['schedule' => 'Esta revisão ainda está sendo preparada. Aguarde e tente novamente com a mesma solicitação.']);
        }
    }

    public function executeApproved(Approval $approval): array
    {
        $approval->refresh();
        $user = User::findOrFail($approval->user_id);
        $data = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if (! $approval->permitsUserAccess($user) || $approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || $approval->type !== 'manual' || $approval->project_id !== null || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ! in_array($data['action'] ?? '', self::ACTIONS, true)) {
            throw new RuntimeException('A revisão da agenda não está autorizada para execução.');
        }
        if (! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $data, $revision)) || ! hash_equals((string) ($approval->payload['request_hash'] ?? ''), $this->definition->digest([$tenant, $data]))) {
            throw new RuntimeException('A revisão da agenda foi alterada. Prepare outra autorização.');
        }
        if (isset($approval->payload['schedule_attempt']['started_at'])) {
            throw new RuntimeException('Uma tentativa desta configuração já foi iniciada. Confira o recurso indicado no catálogo antes de preparar outra revisão.');
        }
        [$connection, $environment] = $this->access($user, $tenant);
        $this->assertGeneration($connection, $revision['connection_generation'] ?? null);
        $this->policy->assertCommandAllowed($tenant, [$data['action'] === 'create_schedule' ? 'new' : 'push', $data['resource'] ?? 'schedule'], $connection);
        try {
            return Cache::lock('schedule-write:'.$tenant, 630)->block(3, function () use ($approval, $user, $connection, $environment, $data, $revision): array {
                return $this->withContext($connection, $environment, function (Project $context) use ($approval, $user, $connection, $data, $revision): array {
                    $inventory = $this->inventory($connection, $context);
                    $current = $this->revision($connection, $context, $data, $inventory);
                    if ($current !== $revision) {
                        throw new RuntimeException('A agenda, suas fontes ou seu acesso mudaram após a revisão. Reabra a configuração e confira outra autorização.');
                    }
                    $ref = $data['resource'];
                    $created = false;
                    $started = false;
                    try {
                        if ($data['action'] === 'create_schedule') {
                            $this->assertGeneration($connection, $revision['connection_generation']);
                            $approval->update(['payload' => [...$approval->payload, 'schedule_attempt' => ['started_at' => now()->toIso8601String()]]]);
                            $started = true;
                            $this->lumo->run($connection, ['new', 'schedule', '--name', $data['values']['name'], '--agent', (string) $data['values']['agent']], $context, 90);
                            $documents = $this->catalog->workingMetadata($context);
                            $new = array_values(array_filter(array_keys($documents), fn (string $reference): bool => str_starts_with($reference, 'schedule:') && ! isset($inventory['schedules'][substr($reference, 9)])));
                            if (count($new) !== 1) {
                                throw new RuntimeException('O BI não confirmou uma única agenda nova.');
                            }
                            $ref = $new[0];
                            $created = true;
                            $approval->update(['payload' => [...$approval->payload, 'schedule_attempt' => [...$approval->payload['schedule_attempt'], 'created_resource' => $ref]]]);
                            ManagedBiResource::create(['user_id' => $user->id, 'project_id' => null, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'origin' => 'manual_schedule', 'status' => 'config_pending', 'receipt' => ['approval_id' => $approval->id, 'created_at' => now()->toIso8601String()]]);
                            $this->lumo->run($connection, ['schedule', 'pause', $ref], $context, 60);
                            $this->lumo->run($connection, ['pull', $ref], $context, 90);
                            [$path, $body, $header] = $this->document($context, $ref);
                            if (($body['ativo'] ?? null) !== false || ($body['flows'] ?? null) !== []) {
                                throw new RuntimeException('A agenda nova não foi confirmada inativa e vazia antes de receber as fontes.');
                            }
                            $approval->update(['payload' => [...$approval->payload, 'schedule_attempt' => [...$approval->payload['schedule_attempt'], 'paused_confirmed' => true]]]);
                        } else {
                            [$path, $body, $header] = $this->document($context, $ref);
                        }
                        if ($created) {
                            $body['timezone'] = 'America/Sao_Paulo';
                        }
                        $keys = $this->credentialKeys($connection, $context, $data);
                        $candidate = $this->definition->apply($body, $data, $keys);
                        $this->validateSources($connection, $context, $candidate, $inventory);
                        $this->assertCadence(substr($ref, 9), $candidate, $inventory);
                        File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($candidate, 40, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP), 0600);
                        $validation = app(BiResourceValidation::class)->inspect($connection, $context, $ref);
                        if (! $validation['passed']) {
                            throw ValidationException::withMessages(['schedule' => 'A configuração não passou na validação do BI. '.implode(' ', array_slice(array_column($validation['issues'], 'message'), 0, 3))]);
                        }
                        $this->assertGeneration($connection, $revision['connection_generation']);
                        $expected = $this->semanticVersion($candidate);
                        $approval->update(['payload' => [...$approval->payload, 'schedule_attempt' => [...($approval->payload['schedule_attempt'] ?? []), 'started_at' => now()->toIso8601String(), 'resource' => $ref, 'expected_configuration' => $expected, 'push_started' => true]]]);
                        $started = true;
                        $this->lumo->run($connection, ['push', $ref], $context, 90);
                        $this->lumo->run($connection, ['pull', $ref], $context, 90);
                        [, $saved] = $this->document($context, $ref);
                        if (! hash_equals($expected, $this->semanticVersion($saved))) {
                            throw new RuntimeException('A configuração enviada não foi confirmada integralmente pelo BI.');
                        }
                        $this->assertGeneration($connection, $revision['connection_generation']);
                        ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $context->tenant_id)->where('resource', $ref)->where('origin', 'manual_schedule')->update(['status' => 'configured']);
                        Audit::record($user->id, 'bi.schedule.configured', 'Configuração de agenda salva e conferida no BI.', null, ['approval_id' => $approval->id, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'created' => $created, 'active' => $saved['ativo']]);

                        return ['resource' => $ref, 'confirmed_name' => $saved['nome'], 'configuration_verified' => true, 'active' => $saved['ativo'], 'created' => $created, 'test_load_requested' => false, 'message' => 'Agenda salva e conferida no G-Analise BI. Nenhuma carga de teste foi solicitada por esta configuração.'];
                    } catch (Throwable $failure) {
                        if ($created) {
                            throw new RuntimeException('A agenda '.$ref.' foi criada, mas a configuração final não foi confirmada. Confira este recurso no catálogo; não crie outra agenda para repetir a tentativa.', previous: $failure);
                        }
                        if ($started) {
                            throw new RuntimeException('O resultado da configuração não foi confirmado. Confira a agenda no catálogo antes de preparar outra revisão; ela não será reenviada automaticamente.', previous: $failure);
                        }
                        throw $failure;
                    } finally {
                        if ($started) {
                            $this->catalog->invalidate((string) $context->tenant_id, $connection);
                        }
                    }
                }, $approval);
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Outra configuração de agenda está em andamento neste ambiente. Aguarde e confira o resultado antes de preparar outra revisão.');
        }
    }

    public function verifyConfiguration(User $user, string $id): array
    {
        $approval = Approval::where('user_id', $user->id)->whereKey($id)->firstOrFail();
        $data = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $attempt = $approval->payload['schedule_attempt'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        abort_unless($approval->type === 'manual' && $approval->project_id === null && $approval->agent_run_id === null && ($approval->payload['origin'] ?? '') === 'manual' && in_array($data['action'] ?? '', self::ACTIONS, true), 404);
        abort_unless($approval->permitsUserAccess($user), 403, 'Seu acesso mudou. Esta conferência não pode usar a autorização antiga.');
        abort_unless($approval->status === 'failed' && ($attempt['push_started'] ?? false) === true && preg_match('/^schedule:[1-9][0-9]*$/D', (string) ($attempt['resource'] ?? '')) && preg_match('/^[a-f0-9]{64}$/D', (string) ($attempt['expected_configuration'] ?? '')), 409, 'Não há um envio incerto disponível para conferência nesta revisão.');
        abort_unless(hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $data, $revision)) && hash_equals((string) ($approval->payload['request_hash'] ?? ''), $this->definition->digest([$tenant, $data])), 409, 'A revisão foi alterada. Confira o recurso pelo catálogo.');
        [$connection, $environment] = $this->access($user, $tenant);
        $this->assertGeneration($connection, $revision['connection_generation'] ?? null);
        try {
            return Cache::lock('bi-operation:'.$approval->id, 180)->block(3, function () use ($user, $approval, $connection, $environment, $attempt, $revision): array {
                return $this->withContext($connection, $environment, function (Project $context) use ($user, $approval, $connection, $attempt, $revision): array {
                    $ref = $attempt['resource'];
                    $listed = $this->catalog->rows($connection, $context, 'schedule');
                    abort_unless(collect($listed)->contains(fn (array $row): bool => 'schedule:'.$row['id'] === $ref), 404, 'Esta agenda não está disponível para sua sessão.');
                    $this->lumo->run($connection, ['pull', $ref], $context, 90);
                    [, $body] = $this->document($context, $ref);
                    $matches = hash_equals($attempt['expected_configuration'], $this->semanticVersion($body));
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $result = ['resource' => $ref, 'confirmed_name' => $body['nome'], 'active' => $body['ativo'], 'configuration_matches' => $matches, 'checked_at' => now()->toIso8601String(), 'test_load_requested' => false, 'message' => $matches ? 'A configuração atual corresponde ao envio desta revisão. A falha original foi preservada no histórico. Esta conferência não solicita nem comprova uma carga de dados.' : 'A configuração atual difere do envio desta revisão. Confira a agenda no catálogo antes de preparar outra alteração. Nenhum envio foi repetido.'];
                    $approval->update(['payload' => [...$approval->payload, 'schedule_verification' => $result]]);
                    if ($matches) {
                        ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $context->tenant_id)->where('resource', $ref)->where('origin', 'manual_schedule')->update(['status' => 'configured']);
                    }
                    Audit::record($user->id, 'bi.schedule.configuration_checked', 'Resultado incerto da configuração conferido sem novo envio.', null, ['approval_id' => $approval->id, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'configuration_matches' => $matches]);

                    return ['approval' => $approval->refresh(), 'verification' => $result];
                });
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Esta solicitação está sendo conferida. Aguarde antes de consultar novamente.');
        }
    }

    public function cleanup(Approval $approval): void
    {
        if ($approval->project_id === null && in_array($approval->payload['action']['action'] ?? '', self::ACTIONS, true) && Str::isUuid($approval->id)) {
            $context = new Project(['user_id' => $approval->user_id, 'tenant_id' => (string) ($approval->payload['revision']['tenant_id'] ?? '')]);
            $context->id = 'schedule-operation-'.$approval->id;
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function revision(Connection $connection, Project $context, array $data, ?array $inventory = null): array
    {
        $generation = $connection->metadata['session_generation'] ?? null;
        $inventory ??= $this->inventory($connection, $context);
        $source = null;
        if ($data['action'] === 'update_schedule') {
            abort_unless(isset($inventory['schedules'][substr($data['resource'], 9)]), 404, 'Esta agenda não está disponível neste ambiente para sua sessão.');
            [, $source] = $this->document($context, $data['resource']);
            abort_unless(hash_equals($data['source_version'], $this->definition->version($source)), 409, 'A agenda mudou. Reabra o formulário para conferir a configuração atual.');
        }
        $keys = $this->credentialKeys($connection, $context, $data);
        $candidate = $this->definition->apply($source ?? $this->definition->defaults(), $data, $keys);
        $sources = $this->validateSources($connection, $context, $candidate, $inventory);
        $this->assertCadence($data['resource'] === null ? 'new' : substr($data['resource'], 9), $candidate, $inventory);
        foreach ($inventory['schedules'] as $id => $schedule) {
            if (($data['resource'] ?? '') !== 'schedule:'.$id && mb_strtolower(trim($schedule['nome'])) === mb_strtolower(trim($candidate['nome']))) {
                throw new RuntimeException('Já existe uma agenda com este nome. Escolha outro nome ou edite a agenda existente.');
            }
        }
        if ($source !== null && $this->definition->version($source) === $this->definition->version($candidate)) {
            throw ValidationException::withMessages(['schedule' => 'Nenhuma alteração foi identificada em relação à versão atual.']);
        }
        if ($source !== null) {
            $this->validateEditedProposal($connection, $context, $data['resource'], $candidate);
        }
        $this->assertGeneration($connection, $generation);

        return ['tenant_id' => (string) $context->tenant_id, 'resource' => $data['resource'], 'connection_generation' => $generation, 'source_digest' => $source === null ? null : $this->definition->version($source), 'candidate_digest' => $this->semanticVersion($candidate), 'sources' => $sources, 'credential_mapping_digest' => $this->definition->digest($keys), 'schedule' => $this->safeSchedule($candidate), 'schedule_edit' => ['creating' => $source === null, 'name' => $candidate['nome'], 'agent' => $inventory['agents'][(string) $candidate['tokenId']], 'previous' => $source === null ? null : [...$this->safeSchedule($source), 'agent_name' => $inventory['agents'][(string) $source['tokenId']]['name'] ?? 'Agente #'.$source['tokenId'], 'sources' => array_map(fn (array $binding): array => ['name' => $inventory['flows'][(string) $binding['flow_etl_id']]['name'] ?? 'Fonte #'.$binding['flow_etl_id'], 'context' => $binding['context']], $source['flows']), 'notifications' => ['on_error' => $source['sendmail_error'] ?? false, 'on_success' => $source['sendmail_success'] ?? false, 'emails' => $source['sendmail_emails'] ?? []], 'parallel_lane' => $source['parallel_lane'] ?? false], 'active' => $candidate['ativo'], 'sources' => $sources, 'notifications' => ['on_error' => $candidate['sendmail_error'] ?? false, 'on_success' => $candidate['sendmail_success'] ?? false, 'emails' => $candidate['sendmail_emails'] ?? []], 'parallel_lane' => $candidate['parallel_lane'] ?? false], 'impact' => $candidate['ativo'] ? 'A agenda ficará ativa e poderá iniciar cargas automaticamente nos horários apresentados. A ordem das fontes será respeitada. Salvar a configuração não comprova uma carga concluída.' : 'A agenda ficará inativa. Nenhuma carga será solicitada por esta configuração. Para ativar depois, será necessária outra revisão.'];
    }

    private function validateEditedProposal(Connection $connection, Project $context, string $reference, array $candidate): void
    {
        [$path, , $header] = $this->document($context, $reference);
        $original = File::get($path);
        try {
            File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($candidate, 40, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP), 0600);
            $validation = app(BiResourceValidation::class)->inspect($connection, $context, $reference);
            if (! $validation['passed']) {
                throw ValidationException::withMessages(['schedule' => 'A edição não passou na validação do BI. Confira os horários e as fontes antes de autorizar. '.implode(' ', array_slice(array_column($validation['issues'], 'message'), 0, 3))]);
            }
        } finally {
            File::replace($path, $original, 0600);
        }
    }

    private function inventory(Connection $connection, Project $context, bool $connections = false): array
    {
        $rows = [];
        foreach (['schedule', 'flow', 'agent'] as $kind) {
            $rows[$kind] = $this->catalog->rows($connection, $context, $kind);
        }
        $references = [];
        foreach (['schedule', 'flow'] as $kind) {
            foreach ($rows[$kind] as $row) {
                $references[] = $kind.':'.$row['id'];
            }
        }
        foreach (array_chunk($references, 100) as $batch) {
            $this->lumo->run($connection, ['pull', ...$batch], $context, 150);
        }
        $documents = $this->catalog->workingMetadata($context, $references);
        $schedules = [];
        foreach ($rows['schedule'] as $row) {
            $body = $documents['schedule:'.$row['id']] ?? null;
            if (! is_array($body) || ! is_string($body['nome'] ?? null) || ! is_bool($body['ativo'] ?? null) || ! is_array($body['flows'] ?? null) || ! array_is_list($body['flows']) || ! is_array($body['triggers'] ?? null) || ! array_is_list($body['triggers'])) {
                throw new RuntimeException('Não foi possível conferir a configuração de todas as agendas existentes. Nenhuma nova carga foi preparada.');
            }
            foreach ($body['flows'] as $binding) {
                if (! is_array($binding) || ! ctype_digit((string) ($binding['flow_etl_id'] ?? '')) || (int) $binding['flow_etl_id'] < 1 || ! is_string($binding['context'] ?? null) || $binding['context'] === '') {
                    throw new RuntimeException('Os vínculos de uma agenda existente chegaram incompletos. Nenhuma nova carga foi preparada.');
                }
            }
            foreach ($body['triggers'] as $trigger) {
                if (! is_array($trigger) || ! is_string($trigger['type'] ?? null)) {
                    throw new RuntimeException('Os horários de uma agenda existente chegaram incompletos. Nenhuma nova carga foi preparada.');
                }
            }
            $schedules[(string) $row['id']] = $body;
        }
        $agents = [];
        foreach ($rows['agent'] as $row) {
            $agents[(string) $row['id']] = ['id' => (string) $row['id'], 'name' => (string) ($row['nome'] ?? $row['name'] ?? 'Agente #'.$row['id'])];
        }
        $flows = [];
        $flowBodies = [];
        foreach ($rows['flow'] as $row) {
            $id = (string) $row['id'];
            $body = $documents['flow:'.$id] ?? [];
            $agent = (string) ($body['tokenId'] ?? '');
            $published = ctype_digit((string) ($body['deskId'] ?? '')) && (int) $body['deskId'] > 0;
            $existing = [];
            foreach ($schedules as $scheduleId => $schedule) {
                foreach ($schedule['flows'] as $binding) {
                    if ((string) ($binding['flow_etl_id'] ?? '') === $id && ($binding['context'] ?? '') !== 'KeySweep') {
                        $existing[] = ['id' => (string) $scheduleId, 'name' => $schedule['nome'], 'active' => $schedule['ativo'], 'frequency' => app(BiScheduleTiming::class)->frequency($schedule['triggers'])];
                    }
                }
            }
            $flows[$id] = ['id' => $id, 'name' => (string) ($body['nome'] ?? $row['nome'] ?? $row['name'] ?? 'Fonte #'.$id), 'agent_id' => isset($agents[$agent]) ? $agent : null, 'published' => $published, 'load_type' => $body['load_type'] ?? null, 'eligible' => $published && isset($agents[$agent]) && in_array($body['load_type'] ?? null, ['Total', 'Incremental', 'Temporal'], true), 'schedules' => $existing, 'has_key_sweep' => is_array($body['key_sweep'] ?? null) && ! empty($body['key_sweep']['sql'])];
            $flowBodies[$id] = $body;
        }
        $credentials = [];
        $available = true;
        $reason = null;
        if ($connections) {
            try {
                foreach ($this->catalog->rows($connection, $context, 'credential') as $row) {
                    $credentials[] = ['id' => (string) $row['id'], 'name' => (string) ($row['nome'] ?? $row['name'] ?? 'Conexão #'.$row['id'])];
                }
            } catch (RuntimeException $failure) {
                if ($failure instanceof BiBusy || $connection->fresh()?->status !== 'connected') {
                    throw $failure;
                }
                $available = false;
                $reason = 'As conexões de detecção não estão disponíveis para esta sessão. Os horários comuns continuam disponíveis.';
            }
        }

        return ['schedules' => $schedules, 'flows' => $flows, 'flow_bodies' => $flowBodies, 'agents' => $agents, 'credentials' => $credentials, 'credentials_available' => $available, 'credentials_message' => $reason];
    }

    private function validateSources(Connection $connection, Project $context, array $candidate, array $inventory): array
    {
        $agent = (string) $candidate['tokenId'];
        if (! isset($inventory['agents'][$agent])) {
            throw ValidationException::withMessages(['agent' => 'O agente escolhido não está disponível neste ambiente.']);
        }
        $sources = [];
        foreach ($candidate['flows'] as $binding) {
            $id = (string) $binding['flow_etl_id'];
            $flow = $inventory['flows'][$id] ?? null;
            if (! $flow || ! $flow['eligible']) {
                throw ValidationException::withMessages(['flows' => 'Uma fonte não está disponível ou ainda está privada. Sua publicação exige autorização própria antes do agendamento.']);
            }
            if ($flow['agent_id'] !== $agent) {
                throw ValidationException::withMessages(['flows' => 'Todas as fontes precisam usar o mesmo agente da agenda. Confira a fonte “'.$flow['name'].'”.']);
            }
            $load = (string) $binding['context'];
            if ($load === 'KeySweep') {
                $body = $inventory['flow_bodies'][$id];
                $tableId = (string) ($body['table']['id'] ?? '');
                if ($flow['load_type'] !== 'Incremental' || ! $flow['has_key_sweep'] || ! ctype_digit($tableId) || (int) $tableId < 1 || ! collect($this->catalog->rows($connection, $context, 'table'))->contains(fn (array $row): bool => (string) $row['id'] === $tableId)) {
                    throw ValidationException::withMessages(['flows' => 'A sincronização de exclusões precisa de uma fonte incremental e uma tabela de destino compatível e acessível.']);
                }
                $this->lumo->run($connection, ['pull', 'table:'.$tableId], $context, 90);
                $table = $this->catalog->workingMetadata($context, ['table:'.$tableId])['table:'.$tableId] ?? [];
                if (($table['table_type'] ?? null) !== 'table' || ($table['key_type'] ?? null) !== 'unique' || empty($table['key_columns'])) {
                    throw ValidationException::withMessages(['flows' => 'A tabela de destino precisa de chaves únicas para sincronizar exclusões.']);
                }
            } elseif (! app(RefreshCoverage::class)->contextMatches($flow['load_type'], $load)) {
                throw ValidationException::withMessages(['flows' => 'A forma de carga escolhida não é compatível com a fonte “'.$flow['name'].'”.']);
            }
            $sources[] = ['id' => $id, 'name' => $flow['name'], 'agent_id' => $agent, 'load_type' => $flow['load_type'], 'context' => $load, 'version' => $this->definition->version($inventory['flow_bodies'][$id])];
        }

        return $sources;
    }

    private function credentialKeys(Connection $connection, Project $context, array $data): array
    {
        $ids = [];
        foreach ($data['values']['triggers'] ?? [] as $trigger) {
            if (isset($trigger['probe']['credential_id'])) {
                $ids[] = (string) $trigger['probe']['credential_id'];
            }
        }
        if ($ids === []) {
            return [];
        }
        $readable = array_map(fn (array $row): string => (string) $row['id'], $this->catalog->rows($connection, $context, 'credential'));
        $keys = [];
        foreach (array_unique($ids) as $id) {
            abort_unless(in_array($id, $readable, true), 422, 'A conexão da detecção não está disponível neste ambiente para sua sessão.');
            $data = $this->lumo->run($connection, ['info', 'credential:'.$id], $context, 60)['data'];
            $body = is_array($data) ? ($data['payload'] ?? $data) : [];
            if (! is_array($body) || (string) ($body['id'] ?? '') !== $id || ! is_string($body['key'] ?? null) || $body['key'] === '') {
                throw new RuntimeException('O BI não confirmou a identidade da conexão de detecção.');
            }
            $keys[$id] = $body['key'];
        }

        return $keys;
    }

    private function assertCadence(string $id, array $candidate, array $inventory): void
    {
        $comparable = json_decode(json_encode($candidate, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        try {
            app(ScheduleChanges::class)->assertNoAdditionalLoad($id, $comparable, $inventory['schedules']);
        } catch (RuntimeException $failure) {
            $message = preg_replace_callback('/\b(flow|schedule):([0-9]+)\b/', function (array $match) use ($inventory): string {
                $name = $match[1] === 'flow' ? ($inventory['flows'][$match[2]]['name'] ?? null) : ($inventory['schedules'][$match[2]]['nome'] ?? null);

                return is_string($name) && $name !== '' ? '“'.$name.'” ('.$match[0].')' : $match[0];
            }, $failure->getMessage());
            throw new RuntimeException($message, previous: $failure);
        }
    }

    private function form(array $body): array
    {
        $triggers = [];
        foreach ($this->definition->keyed($body['triggers'], 'trigger') as $key => $trigger) {
            $triggers[] = ['key' => $key, 'type' => $trigger['type'] ?? null, 'period' => $trigger['period'] ?? null, 'time' => in_array($trigger['type'] ?? null, ['Day', 'Week', 'Month'], true) ? ($trigger['start_when'] ?? null) : null, 'anchor' => in_array($trigger['type'] ?? null, ['Hour', 'Minute', 'CDC'], true) ? ($trigger['start_when'] ?? null) : null, 'window_start' => $trigger['start_time'] ?? null, 'window_end' => $trigger['end_time'] ?? null, 'probe_configured' => isset($trigger['config']), 'label' => app(BiScheduleTiming::class)->frequency([$trigger])];
        }
        $flows = [];
        foreach ($this->definition->keyed($body['flows'], 'flow') as $key => $binding) {
            $flows[] = ['key' => $key, 'flow_id' => (string) $binding['flow_etl_id'], 'context' => (string) $binding['context']];
        }

        return ['name' => $body['nome'], 'active' => $body['ativo'], 'agent' => (string) $body['tokenId'], 'timezone' => $body['timezone'] ?? null, 'triggers' => $triggers, 'flows' => $flows, 'notifications' => ['on_error' => $body['sendmail_error'] ?? false, 'on_success' => $body['sendmail_success'] ?? false, 'emails' => $body['sendmail_emails'] ?? []], 'parallel_lane' => $body['parallel_lane'] ?? false, 'daily_protected' => $body['ativo'] && app(BiScheduleTiming::class)->daily($body['triggers']) && collect($body['flows'])->contains(fn (array $binding): bool => ($binding['context'] ?? '') !== 'KeySweep')];
    }

    private function safeSchedule(array $body): array
    {
        return ['nome' => $body['nome'], 'ativo' => $body['ativo'], 'tokenId' => $body['tokenId'], 'timezone' => $body['timezone'] ?? null, 'flows' => array_map(fn (array $binding): array => ['flow_etl_id' => $binding['flow_etl_id'], 'context' => $binding['context']], $body['flows']), 'triggers' => array_map(fn (array $trigger): array => array_intersect_key($trigger, array_fill_keys(['type', 'period', 'start_when', 'start_time', 'end_time', 'weekdays', 'days', 'days_of_week', 'end_when'], true)), $body['triggers'])];
    }

    private function semanticVersion(array $body): string
    {
        $triggers = $body['triggers'] ?? [];
        foreach ($triggers as &$trigger) {
            if (($trigger['type'] ?? null) === 'Day') {
                unset($trigger['period'], $trigger['start_time'], $trigger['end_time']);
            } elseif (in_array($trigger['type'] ?? null, ['Week', 'Month'], true)) {
                unset($trigger['start_time'], $trigger['end_time']);
            }
            if (in_array($trigger['type'] ?? null, ['Hour', 'Minute', 'CDC'], true) && is_string($trigger['start_when'] ?? null)) {
                $trigger['start_when'] = $this->definition->canonicalInstant($trigger['start_when']);
            }
        }
        unset($trigger);
        $body['triggers'] = $triggers;

        return $this->definition->version($body);
    }

    private function document(Project $context, string $reference): array
    {
        $root = app(WorkspaceFiles::class)->path($context).'/schedules';
        $matches = [];
        foreach (is_dir($root) && ! is_link($root) ? File::files($root) : [] as $file) {
            if ($file->isLink() || $file->getSize() > 2000000 || $file->getExtension() !== 'yaml') {
                continue;
            }
            $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $header = Yaml::parse($parts[0]);
            if (! is_array($header) || ($header['kind'] ?? null) !== 'schedule' || (string) ($header['tenantId'] ?? '') !== (string) $context->tenant_id || $reference !== 'schedule:'.($header['id'] ?? '')) {
                continue;
            }
            $body = Yaml::parse($parts[1], Yaml::PARSE_OBJECT_FOR_MAP);
            $body = $body instanceof \stdClass ? (array) $body : $body;
            if (! is_array($body) || ! is_string($body['nome'] ?? null) || ! is_bool($body['ativo'] ?? null) || ! isset($body['tokenId'])) {
                throw new RuntimeException('A configuração da agenda não chegou em formato completo.');
            }
            foreach (['triggers', 'flows'] as $field) {
                if (! is_array($body[$field] ?? null) || ! array_is_list($body[$field])) {
                    throw new RuntimeException('Os itens da agenda não chegaram em formato reconhecido.');
                }
                $body[$field] = array_map(fn (mixed $row): array => $row instanceof \stdClass ? (array) $row : (is_array($row) ? $row : throw new RuntimeException('Item de agenda inválido.')), $body[$field]);
            }
            $matches[] = [$file->getPathname(), $body, $header];
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Não foi possível identificar uma configuração canônica única desta agenda.');
        }

        return $matches[0];
    }

    private function withContext(Connection $connection, array $environment, callable $operation, ?Approval $approval = null): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => (string) $environment['id'], 'tenant_name' => $environment['name'], 'name' => 'Configuração de agenda']);
        $context->id = $approval ? 'schedule-operation-'.$approval->id : 'schedule-query-'.Str::uuid();
        try {
            $this->lumo->initialize($connection, $context, true);

            return $operation($context);
        } finally {
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function access(User $user, string $tenant): array
    {
        $current = $user->fresh();
        abort_if(! $current?->canStartWork() || $current->role === 'viewer' || $current->access_version !== $user->access_version, 403, 'Seu acesso não permite configurar agendas.');

        return $this->catalog->access($user, $tenant);
    }

    private function assertGeneration(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! is_string($generation) || $generation === '' || ! $current || $current->status !== 'connected' || ! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== $connection->operationAccessVersion || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Sua conexão ou seu acesso mudou durante a operação. Prepare outra revisão.');
        }
    }

    private function fingerprint(int $user, string $tenant, array $data, array $revision): string
    {
        return $this->definition->digest(['schedule-review-v1', $user, $tenant, $data, $revision]);
    }
}
