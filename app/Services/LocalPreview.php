<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LocalPreview
{
    public function enabled(): bool
    {
        return app()->environment('local') && config('horus.preview');
    }

    public function signIn(): User
    {
        abort_unless($this->enabled(), 404);
        $user = User::firstOrCreate(['email' => 'preview@g-analise.local'], ['name' => 'Equipe Inovadora', 'password' => Hash::make(Str::random(64))]);
        if ($user->role !== 'owner') {
            $user->forceFill(['role' => 'owner'])->save();
        }
        Auth::login($user);
        request()->session()->regenerate();
        if (! Project::where('user_id', $user->id)->exists()) {
            $this->seed($user);
        }

        return $user;
    }

    private function seed(User $user): void
    {
        foreach ([
            ['Visão comercial', 'Receita, metas e desempenho da equipe de vendas.', 'violet', 'Comercial'],
            ['Saúde financeira', 'Recebimentos, despesas e evolução do resultado.', 'mint', 'Financeiro'],
            ['Operação em foco', 'Indicadores para acompanhar a rotina da operação.', 'amber', 'Operações'],
        ] as [$name,$description,$color,$area]) {
            $project = Project::create(['user_id' => $user->id, 'name' => $name, 'description' => $description, 'color' => $color, 'tenant_name' => 'Ambiente de exemplo', 'is_example' => true]);
            $conversation = Conversation::create(['project_id' => $project->id, 'user_id' => $user->id, 'title' => 'Primeiros passos']);
            Message::create(['conversation_id' => $conversation->id, 'role' => 'system', 'content' => 'Este projeto é um exemplo para explorar o G-Analise IA. Conecte sua conta ChatGPT e o G-Analise BI para criar dashboards com dados reais. Nenhuma alteração foi enviada ao G-Analise.']);
            Artifact::create(['project_id' => $project->id, 'user_id' => $user->id, 'name' => $name, 'kind' => 'dashboard_preview', 'mime' => 'application/json', 'metadata' => ['example' => true, 'area' => $area, 'metrics' => [['label' => 'Receita total', 'value' => 'R$ 248.560', 'change' => '+12,8%'], ['label' => 'Pedidos', 'value' => '1.284', 'change' => '+8,2%'], ['label' => 'Ticket médio', 'value' => 'R$ 193,58', 'change' => '+4,3%']], 'series' => [28, 41, 34, 56, 43, 66, 52, 72, 64, 82, 70, 94]]]);
        }
        Audit::record($user->id, 'workspace.preview', 'Ambiente local de exemplo preparado.');
    }
}
