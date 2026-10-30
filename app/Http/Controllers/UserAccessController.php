<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\UserAccessRevocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserAccessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdministration($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = User::query()->orderBy('name')->orderBy('id');
        if (($data['status'] ?? 'all') !== 'all') {
            $query->where('active', $data['status'] === 'active');
        }
        if (trim($data['search'] ?? '') !== '') {
            $term = mb_strtolower(trim($data['search']));
            $query->where(function ($query) use ($term): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.$term.'%']);
            });
        }
        $page = $query->paginate(25);

        return response()->json(['users' => array_map($this->publicUser(...), $page->items()), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdministration($request);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'email' => ['required', 'email', 'max:254', 'unique:users,email'], 'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])], 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers(), 'max:64', 'regex:/\A.{0,72}\z/sD', 'not_regex:/\x00/']]);
        abort_if(User::whereRaw('LOWER(email) = ?', [$data['email']])->exists(), 422, 'Este e-mail já possui um cadastro.');
        $user = DB::transaction(function () use ($request, $data): User {
            $this->authorizeAdministration($request, true);
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill(['role' => $data['role'], 'active' => true, 'local_login_enabled' => true, 'must_change_password' => true])->save();

            return $user;
        });
        Audit::record($request->user()->id, 'users.created', 'Acesso local cadastrado com troca de senha no primeiro login.', null, ['target_user_id' => $user->id, 'role' => $user->role]);

        return response()->json(['user' => $this->publicUser($user->refresh()), 'message' => 'Usuário cadastrado. Entregue a senha inicial por um canal privado; ele deverá trocá-la no primeiro acesso.'], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdministration($request);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->id)], 'role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])], 'active' => ['required', 'boolean'], 'version' => ['required', 'string', 'regex:/^[0-9]{1,10}$/D'], 'password' => ['nullable', 'confirmed', Password::min(12)->mixedCase()->numbers(), 'max:64', 'regex:/\A.{0,72}\z/sD', 'not_regex:/\x00/']]);
        $actor = $request->user();
        $target = DB::transaction(function () use ($request, $user, $data): User {
            User::whereIn('role', ['owner', 'admin'])->orderBy('id')->lockForUpdate()->get();
            $actor = $this->authorizeAdministration($request, true);
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals((string) $target->access_revision, $data['version']), 409, 'O cadastro mudou. Atualize a lista antes de salvar novamente.');
            abort_if($target->role === 'owner' && $actor->role !== 'owner', 403, 'Somente o administrador principal pode alterar este cadastro.');
            abort_if($data['role'] === 'owner' && $target->role !== 'owner', 422, 'O perfil de administrador principal não pode ser atribuído por este formulário.');
            abort_if($actor->id === $target->id && (! $data['active'] || $data['role'] !== $target->role || ! empty($data['password'])), 422, 'Mantenha seu próprio acesso e perfil. Para trocar sua senha, use Segurança da conta.');
            if ($target->suite_subject !== null) {
                abort_if($data['email'] !== $target->email || $data['role'] !== $target->role || $data['name'] !== $target->name || ! empty($data['password']), 422, 'A identidade e o perfil deste colaborador são gerenciados pela Suíte. Aqui você pode ativar ou inativar seu acesso ao G-Analise IA.');
            }
            abort_if(User::whereKeyNot($target->id)->whereRaw('LOWER(email) = ?', [$data['email']])->exists(), 422, 'Este e-mail já possui um cadastro.');
            $wasAdmin = in_array($target->role, ['owner', 'admin'], true) && $target->active;
            $remainsAdmin = in_array($data['role'], ['owner', 'admin'], true) && $data['active'];
            if ($wasAdmin && ! $remainsAdmin) {
                abort_unless(User::whereKeyNot($target->id)->whereIn('role', ['owner', 'admin'])->where('active', true)->exists(), 422, 'Mantenha pelo menos um administrador ativo.');
            }
            $revoke = ($target->active && ! $data['active']) || ($target->role !== $data['role']) || ($target->email !== $data['email']) || ! empty($data['password']);
            $target->forceFill(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'active' => $data['active']]);
            if (! empty($data['password'])) {
                abort_unless($target->local_login_enabled, 422, 'A senha deste usuário é gerenciada pela Suíte.');
                $target->forceFill(['password' => $data['password'], 'must_change_password' => true]);
            }
            if ($revoke) {
                $target->remember_token = Str::random(60);
                $target->access_version++;
                app(UserAccessRevocation::class)->revoke($target);
            }
            $target->access_revision++;
            $target->save();

            return $target;
        });
        Audit::record($actor->id, 'users.updated', 'Cadastro e acesso local atualizados.', null, ['target_user_id' => $target->id, 'role' => $target->role, 'active' => $target->active, 'password_reset' => ! empty($data['password'])]);

        return response()->json(['user' => $this->publicUser($target), 'message' => 'Acesso atualizado. Alterações de perfil, inativação e redefinição de senha encerram as sessões locais e cancelam tarefas pendentes.']);
    }

    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->local_login_enabled, 422, 'Sua senha é gerenciada pela Suíte.');
        $data = $request->validate(['current_password' => ['required', 'string', 'current_password'], 'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers(), 'max:64', 'regex:/\A.{0,72}\z/sD', 'not_regex:/\x00/']]);
        $user = DB::transaction(function () use ($user, $data, $request): User {
            $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && $fresh->local_login_enabled && (int) $request->session()->get('user_access_version', 0) === $fresh->access_version, 401, 'Sua sessão mudou. Entre novamente antes de trocar sua senha.');
            if (! Hash::check($data['current_password'], $fresh->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha atual mudou. Informe sua senha atual novamente.']);
            }
            $fresh->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => Str::random(60), 'access_version' => $fresh->access_version + 1, 'access_revision' => $fresh->access_revision + 1])->save();
            app(UserAccessRevocation::class)->revoke($fresh, $request->session()->getId());

            return $fresh;
        });
        Auth::setUser($user);
        $request->session()->regenerate();
        $request->session()->put('user_access_version', $user->access_version);
        Audit::record($user->id, 'auth.password.changed', 'Senha pessoal atualizada; outras sessões locais encerradas.');

        return response()->json(['message' => 'Sua senha foi atualizada.', 'user' => $this->publicUser($user)]);
    }

    private function authorizeAdministration(Request $request, bool $lock = false): User
    {
        $query = User::whereKey($request->user()->id);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($user?->canAdministerUsers() && $user->canStartWork() && (int) $request->session()->get('user_access_version', 0) === $user->access_version, 403, 'Somente administradores com uma sessão atual podem gerenciar acessos.');

        return $user;
    }

    /** @return array<string, mixed> */
    private function publicUser(User $user): array
    {
        return [...$user->only(['id', 'name', 'email', 'role', 'active', 'must_change_password', 'last_login_at']), 'role' => $user->role === 'author' ? 'editor' : $user->role, 'auth_provider' => $user->suite_subject !== null ? 'suite' : 'local', 'local_login_enabled' => $user->local_login_enabled, 'version' => (string) $user->access_revision];
    }
}
