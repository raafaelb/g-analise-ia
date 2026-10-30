<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ArtifactImages
{
    private function image(Artifact $artifact): string
    {
        if ($artifact->kind !== 'dashboard_export' || $artifact->mime !== 'image/png' || ! str_starts_with((string) $artifact->path, 'exports/'.$artifact->user_id.'/'.$artifact->project_id.'/')) {
            throw new RuntimeException('Selecione uma imagem real exportada por este projeto.');
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($artifact->path) || $disk->size($artifact->path) > 10000000 || is_link($disk->path($artifact->path))) {
            throw new RuntimeException('Imagem indisponível ou maior que o limite de análise de 10 MB.');
        }
        $size = @getimagesize($disk->path($artifact->path));
        if (! $size || $size['mime'] !== 'image/png') {
            throw new RuntimeException('O arquivo não contém uma imagem PNG válida.');
        }

        return $disk->get($artifact->path);
    }

    public function grant(User $user, Artifact $artifact, Conversation $conversation): void
    {
        if ($artifact->user_id !== $user->id || $conversation->user_id !== $user->id || $artifact->project_id !== $conversation->project_id) {
            throw new RuntimeException('Imagem fora desta conversa autorizada.');
        }
        $image = $this->image($artifact);
        $artifact->update(['metadata' => [...($artifact->metadata ?? []), 'visual_consent' => ['recipient' => 'openai', 'conversation_id' => $conversation->id, 'sha256' => hash('sha256', $image), 'granted_at' => now()->toIso8601String()]]]);
        Audit::record($user->id, 'artifact.visual_consent', 'Envio da imagem específica à OpenAI autorizado para análise nesta conversa.', $artifact->project_id, ['artifact_id' => $artifact->id, 'conversation_id' => $conversation->id]);
    }

    public function authorized(AgentRun $run, string $id): Artifact
    {
        $artifact = Artifact::where('user_id', $run->user_id)->where('project_id', $run->conversation->project_id)->find($id);
        if (! $artifact) {
            throw new RuntimeException('Imagem não encontrada neste projeto.');
        }
        $consent = $artifact->metadata['visual_consent'] ?? [];
        if (($consent['recipient'] ?? '') !== 'openai' || ($consent['conversation_id'] ?? null) !== $run->conversation_id) {
            throw new RuntimeException('Antes da análise visual, abra esta prévia no portal e autorize o envio da imagem à OpenAI nesta conversa.');
        }
        if (! hash_equals($consent['sha256'] ?? '', hash('sha256', $this->image($artifact)))) {
            throw new RuntimeException('A imagem mudou após a autorização. Abra a nova prévia e autorize esse arquivo específico.');
        }

        return $artifact;
    }

    public function content(AgentRun $run, string $id): array
    {
        $artifact = $this->authorized($run, $id);
        Audit::record($run->user_id, 'artifact.visual_shared', 'Imagem autorizada enviada à OpenAI para análise visual.', $artifact->project_id, ['artifact_id' => $artifact->id, 'conversation_id' => $run->conversation_id]);

        return ['type' => 'inputImage', 'imageUrl' => 'data:image/png;base64,'.base64_encode($this->image($artifact))];
    }
}
