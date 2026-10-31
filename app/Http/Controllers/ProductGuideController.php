<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserGuideProgress;
use App\Services\ProductGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductGuideController extends Controller
{
    public function index(Request $request, ProductGuide $guide): JsonResponse
    {
        $user = $this->active($request);
        $content = $guide->content($user);
        $progress = UserGuideProgress::where('user_id', $user->id)->whereIn('page_key', array_keys($content['pages']))->get()->keyBy('page_key');

        return $this->respond([...$content, 'progress' => (object) $progress->map(fn (UserGuideProgress $item): array => $this->state($item))->all()]);
    }

    public function start(Request $request, string $page, ProductGuide $guide): JsonResponse
    {
        $user = $this->active($request);
        $definition = $guide->page($user, $page);
        $this->only($request, ['mode', 'instance_id']);
        $data = $request->validate(['mode' => ['required', Rule::in(['auto', 'manual'])], 'instance_id' => ['required', 'uuid']], ['mode.required' => 'Escolha como iniciar o tour.', 'mode.in' => 'O modo de início do tour é inválido.', 'instance_id.required' => 'Atualize a ajuda antes de iniciar o tour.', 'instance_id.uuid' => 'A identificação do tour é inválida.']);
        $instance = strtolower($data['instance_id']);
        $result = DB::transaction(function () use ($user, $page, $data, $instance, $definition): array {
            $this->lockedUser($user);
            $progress = UserGuideProgress::where('user_id', $user->id)->where('page_key', $page)->lockForUpdate()->first();
            if ($progress && $data['mode'] === 'auto') {
                return ['should_start' => false, 'progress' => $this->state($progress)];
            }
            if ($progress && $progress->last_instance_id === $instance) {
                return ['should_start' => $progress->last_status === 'started', 'progress' => $this->state($progress)];
            }
            if (! $progress) {
                $progress = UserGuideProgress::create(['user_id' => $user->id, 'page_key' => $page, 'first_content_version' => $definition['version'], 'last_content_version' => $definition['version'], 'first_started_at' => now(), 'last_started_at' => now(), 'last_instance_id' => $instance, 'last_status' => 'started']);
            } else {
                $progress->update(['last_content_version' => $definition['version'], 'last_started_at' => now(), 'last_finished_at' => null, 'last_instance_id' => $instance, 'last_step_id' => null, 'last_status' => 'started', 'replay_count' => $progress->replay_count + 1]);
            }

            return ['should_start' => true, 'progress' => $this->state($progress)];
        });

        return $this->respond($result);
    }

    public function event(Request $request, string $page, ProductGuide $guide): JsonResponse
    {
        $user = $this->active($request);
        $definition = $guide->page($user, $page);
        $this->only($request, ['instance_id', 'event', 'step_id']);
        $data = $request->validate(['instance_id' => ['required', 'uuid'], 'event' => ['required', Rule::in(['seen', 'completed', 'dismissed'])], 'step_id' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,79}$/D']], ['event.in' => 'O evento do tour é inválido.', 'step_id.regex' => 'O passo do tour é inválido.']);
        $result = DB::transaction(function () use ($user, $page, $data, $definition): array {
            $this->lockedUser($user);
            $progress = UserGuideProgress::where('user_id', $user->id)->where('page_key', $page)->lockForUpdate()->firstOrFail();
            if ($progress->last_instance_id !== strtolower($data['instance_id'])) {
                return ['accepted' => false, 'progress' => $this->state($progress)];
            }
            if ($progress->last_content_version === $definition['version'] && isset($data['step_id']) && ! in_array($data['step_id'], array_column($definition['steps'], 'id'), true)) {
                throw ValidationException::withMessages(['step_id' => 'Este passo não pertence ao tour desta página.']);
            }
            if ($progress->last_status !== 'started' && $data['event'] !== 'seen') {
                return ['accepted' => false, 'progress' => $this->state($progress)];
            }
            $changes = ['first_seen_at' => $progress->first_seen_at ?? now()];
            if ($progress->last_status === 'started') {
                if (isset($data['step_id'])) {
                    $changes['last_step_id'] = $data['step_id'];
                }
                if ($data['event'] !== 'seen') {
                    $changes['last_status'] = $data['event'];
                    $changes['last_finished_at'] = now();
                    $date = $data['event'] === 'completed' ? 'completed_at' : 'dismissed_at';
                    $changes[$date] = $progress->{$date} ?? now();
                }
            }
            $progress->update($changes);

            return ['accepted' => true, 'progress' => $this->state($progress)];
        });

        return $this->respond($result);
    }

    private function active(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canStartWork(), 403, 'Entre com uma conta ativa para usar a ajuda.');

        return $user;
    }

    private function lockedUser(User $user): void
    {
        $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->canStartWork() && $current->access_version === $user->access_version, 403, 'Seu acesso mudou. Entre novamente para continuar.');
    }

    private function only(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), $keys) !== []) {
            throw ValidationException::withMessages(['guide' => 'Envie somente os campos deste tour.']);
        }
    }

    private function state(UserGuideProgress $progress): array
    {
        return $progress->only(['page_key', 'first_content_version', 'last_content_version', 'first_started_at', 'last_started_at', 'first_seen_at', 'completed_at', 'dismissed_at', 'last_finished_at', 'last_status', 'last_instance_id', 'last_step_id', 'replay_count']);
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
