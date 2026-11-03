<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

class AgentInferenceGrants
{
    private const PREFIX = 'agent-inference:v1:';

    public function issue(AgentRun $run, string $model): AgentInferenceGrant
    {
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,199}$/D', $model), 422, 'Modelo inválido para esta tarefa.');
        $current = AgentRun::find($run->id);
        abort_unless($current && $current->status === 'running' && $current->started_at !== null, 403, 'Esta tarefa não está disponível para inferência.');
        $duration = (int) config('horus.codex.timeout', 900) + 30;
        $maximum = (int) config('horus.codex.gateway_max_requests', 64);
        abort_unless($duration >= 31 && $duration <= 930 && $maximum >= 1 && $maximum <= 1000, 503, 'Os limites da execução de IA precisam ser configurados.');
        $conversation = Conversation::find($current->conversation_id);
        $project = $conversation ? Project::find($conversation->project_id) : null;
        $user = User::find($current->user_id);
        $connection = Connection::where('user_id', $current->user_id)->where('provider', 'openai')->where('status', 'connected')->first();
        $expires = $current->started_at->timestamp + $duration;
        abort_unless($conversation && $project && $user && $connection && is_string($connection->metadata['session_generation'] ?? null) && $connection->metadata['session_generation'] !== '' && $expires > now()->timestamp, 403, 'A autorização desta tarefa não está disponível.');
        $token = 'gaigr_'.Str::random(64);
        $hash = hash('sha256', $token);
        $scope = [
            'token_hash' => $hash, 'run_id' => $current->id, 'started_at' => $current->started_at->toISOString(),
            'conversation_id' => $conversation->id, 'project_id' => $project->id, 'tenant_id' => (string) $project->tenant_id,
            'user_id' => $user->id, 'access_version' => $user->access_version,
            'connection_id' => $connection->id, 'connection_generation' => $connection->metadata['session_generation'],
            'model' => $model, 'expires_at' => $expires, 'maximum_requests' => $maximum,
        ];
        $this->liveScope($scope);
        try {
            return Cache::lock(self::PREFIX.'lock-run:'.$current->id, 5)->block(2, function () use ($scope, $token, $hash): AgentInferenceGrant {
                $this->liveScope($scope);
                $index = $this->index($scope['run_id']);
                $previous = Cache::get($index);
                $requests = 0;
                $previousHash = is_string($previous) ? preg_replace('/^revoked:/', '', $previous) : null;
                if ($previousHash !== null) {
                    abort_unless(preg_match('/^[a-f0-9]{64}$/D', $previousHash), 401, 'A autorização anterior desta tarefa não pôde ser conferida.');
                    $prior = $this->verified($previousHash, requireCurrent: false);
                    abort_unless($prior['scope']['run_id'] === $scope['run_id'], 401, 'A autorização anterior desta tarefa não pôde ser conferida.');
                    if ($prior['scope']['started_at'] === $scope['started_at']) {
                        $requests = $prior['requests'];
                        $scope['maximum_requests'] = min($scope['maximum_requests'], $prior['scope']['maximum_requests']);
                        $scope['expires_at'] = min($scope['expires_at'], $prior['scope']['expires_at']);
                    }
                }
                $seconds = $scope['expires_at'] - now()->timestamp;
                abort_unless($seconds > 0, 403, 'A tarefa ultrapassou seu prazo de execução.');
                Cache::put($this->record($hash), $this->seal(['scope' => $scope, 'requests' => $requests]), $seconds);
                Cache::put($index, $hash, $seconds);
                if ($previousHash !== null) {
                    Cache::forget($this->record($previousHash));
                }

                return new AgentInferenceGrant($token, $scope['run_id'], $scope['expires_at']);
            });
        } catch (LockTimeoutException) {
            abort(409, 'A autorização desta tarefa está sendo atualizada.');
        }
    }

    /** @return array{run: AgentRun, conversation: Conversation, project: Project, user: User, connection: Connection} */
    public function claim(#[SensitiveParameter] string $token, string $model): array
    {
        $hash = $this->tokenHash($token);
        $runId = $this->verified($hash)['scope']['run_id'];
        try {
            return Cache::lock(self::PREFIX.'lock-run:'.$runId, 5)->block(2, function () use ($hash, $model): array {
                $record = $this->verified($hash);
                $scope = $record['scope'];
                abort_unless(hash_equals($scope['model'], $model), 422, 'Esta autorização não permite outro modelo.');
                $this->liveScope($scope);
                abort_if($record['requests'] >= $scope['maximum_requests'], 429, 'A tarefa atingiu seu limite de chamadas de IA.');
                $record['requests']++;
                Cache::put($this->record($hash), $this->seal($record), $scope['expires_at'] - now()->timestamp);
                $this->assertCurrent($scope, $hash);

                return $this->liveScope($scope);
            });
        } catch (LockTimeoutException) {
            abort(409, 'Outra chamada desta tarefa está sendo conferida.');
        }
    }

    /** @return array{run: AgentRun, conversation: Conversation, project: Project, user: User, connection: Connection} */
    public function active(#[SensitiveParameter] string $token): array
    {
        return $this->liveScope($this->verified($this->tokenHash($token))['scope']);
    }

    public function revoke(AgentInferenceGrant $grant): void
    {
        $hash = $this->tokenHash($grant->authorization());
        try {
            Cache::lock(self::PREFIX.'lock-run:'.$grant->runId, 5)->block(2, function () use ($grant, $hash): void {
                $current = Cache::get($this->index($grant->runId));
                if (in_array($current, [$hash, 'revoked:'.$hash], true) && $grant->expiresAt > now()->timestamp) {
                    Cache::put($this->index($grant->runId), 'revoked:'.$hash, $grant->expiresAt - now()->timestamp);
                } else {
                    Cache::forget($this->record($hash));
                }
            });
        } catch (LockTimeoutException) {
            Cache::forget($this->record($hash));
        }
    }

    /** @return array{scope: array<string, mixed>, requests: int} */
    private function verified(string $hash, bool $requireCurrent = true): array
    {
        $record = Cache::get($this->record($hash));
        try {
            $signature = is_array($record) ? ($record['signature'] ?? null) : null;
            unset($record['signature']);
            $valid = is_string($signature) && preg_match('/^[a-f0-9]{64}$/D', $signature) && hash_equals($this->signature($record), $signature);
        } catch (Throwable) {
            $valid = false;
        }
        abort_unless($valid && is_array($record['scope'] ?? null) && is_int($record['requests'] ?? null) && $record['requests'] >= 0, 401, 'Autorização temporária inválida ou expirada.');
        $scope = $record['scope'];
        abort_unless(($scope['token_hash'] ?? null) === $hash && is_string($scope['run_id'] ?? null) && is_string($scope['model'] ?? null) && is_int($scope['expires_at'] ?? null) && $scope['expires_at'] > now()->timestamp && is_int($scope['maximum_requests'] ?? null), 401, 'Autorização temporária inválida ou expirada.');
        if ($requireCurrent) {
            $this->assertCurrent($scope, $hash);
        }

        return $record;
    }

    private function assertCurrent(array $scope, string $hash): void
    {
        abort_unless(Cache::get($this->index($scope['run_id'])) === $hash, 401, 'Autorização temporária inválida ou expirada.');
    }

    /** @return array{run: AgentRun, conversation: Conversation, project: Project, user: User, connection: Connection} */
    private function liveScope(array $scope): array
    {
        $run = AgentRun::find($scope['run_id']);
        $conversation = $run ? Conversation::find($run->conversation_id) : null;
        $project = $conversation ? Project::find($conversation->project_id) : null;
        $user = User::find($scope['user_id']);
        $connection = Connection::find($scope['connection_id']);
        abort_unless(
            $run && in_array($run->status, ['running', 'awaiting_approval'], true) && $run->user_id === $scope['user_id'] && $run->started_at?->toISOString() === $scope['started_at'] &&
            $conversation && $conversation->id === $scope['conversation_id'] && $conversation->user_id === $scope['user_id'] &&
            $project && $project->id === $scope['project_id'] && $project->user_id === $scope['user_id'] && $project->status !== 'archived' && filled($project->tenant_id) && (string) $project->tenant_id === $scope['tenant_id'] &&
            $user?->canStartWork() && $user->role !== 'viewer' && $user->access_version === $scope['access_version'] &&
            $connection && $connection->user_id === $scope['user_id'] && $connection->provider === 'openai' && $connection->status === 'connected' && ($connection->metadata['plan_usage'] ?? false) === true && ($connection->metadata['session_generation'] ?? null) === $scope['connection_generation'],
            403, 'O acesso ou o contexto desta tarefa mudou. A inferência não está autorizada.'
        );

        return compact('run', 'conversation', 'project', 'user', 'connection');
    }

    private function seal(array $record): array
    {
        unset($record['signature']);

        return [...$record, 'signature' => $this->signature($record)];
    }

    private function signature(array $record): string
    {
        return hash_hmac('sha256', 'agent-inference-record:v1|'.json_encode($record, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function tokenHash(#[SensitiveParameter] string $token): string
    {
        abort_unless(preg_match('/^gaigr_[A-Za-z0-9]{64}$/D', $token), 401, 'Autorização temporária inválida ou expirada.');

        return hash('sha256', $token);
    }

    private function record(string $hash): string
    {
        return self::PREFIX.'record:'.$hash;
    }

    private function index(string $runId): string
    {
        return self::PREFIX.'run:'.$runId;
    }
}
