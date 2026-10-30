<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\SuiteIdentity;
use App\Services\UserAccessRevocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SuiteAuthController extends Controller
{
    public function login(Request $request, SuiteIdentity $suite): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254'], 'password' => ['required', 'string', 'max:64', 'not_regex:/[\r\n]/', 'not_regex:/^\s|\s$/uD']], ['password.not_regex' => 'Esta integração da Suíte não aceita senhas com quebras de linha ou espaços no início ou no fim. Ajuste sua senha na Suíte para continuar.']);
        $email = strtolower(trim($data['email']));
        $key = 'suite-login:'.hash_hmac('sha256', $email.'|'.$request->ip(), (string) config('app.key'));
        $blocked = Cache::get($key.':blocked_until');
        if (is_int($blocked) && $blocked > now()->timestamp) {
            return $this->limited($request, $blocked - now()->timestamp);
        }
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->limited($request, RateLimiter::availableIn($key));
        }
        $result = $suite->authenticate($email, $data['password'], $request->ip());
        unset($data['password']);
        if ($result['state'] === 'limited') {
            Cache::put($key.':blocked_until', now()->timestamp + $result['retry_after'], $result['retry_after']);

            return $this->limited($request, $result['retry_after']);
        }
        if ($result['state'] !== 'verified') {
            if ($result['state'] === 'invalid') {
                RateLimiter::hit($key, 300);
            }
            throw ValidationException::withMessages(['email' => $result['state'] === 'invalid' ? 'Não foi possível entrar com essas credenciais da Suíte.' : 'A Suíte está indisponível para autenticação. Tente novamente mais tarde.']);
        }
        $identity = $result['identity'];
        $user = DB::transaction(function () use ($suite, $identity): User {
            $linked = User::where('suite_issuer', $suite->issuer())->where('suite_subject', $identity['uuid'])->lockForUpdate()->first();
            $sameEmail = User::whereRaw('LOWER(email) = ?', [$identity['email']])->lockForUpdate()->get();
            if ($sameEmail->count() > 1 || ($linked && $sameEmail->contains(fn (User $candidate) => $candidate->id !== $linked->id))) {
                throw ValidationException::withMessages(['email' => 'Não foi possível associar esta identidade. Peça ao administrador para conferir seu cadastro.']);
            }
            $user = $linked ?? $sameEmail->first();
            if ($user && (! $user->active || ($user->suite_subject !== null && ($user->suite_subject !== $identity['uuid'] || $user->suite_issuer !== $suite->issuer())))) {
                throw ValidationException::withMessages(['email' => 'Não foi possível entrar com essas credenciais da Suíte.']);
            }
            if ($user && ($user->suite_subject === null || $user->local_login_enabled || $user->email !== $identity['email'] || ! in_array($user->role, ['owner', 'admin'], true))) {
                $user->access_version++;
                $user->remember_token = Str::random(60);
                app(UserAccessRevocation::class)->revoke($user);
            }
            $user ??= new User(['password' => Str::random(64)]);
            $user->forceFill(['name' => $identity['name'], 'email' => $identity['email'], 'suite_issuer' => $suite->issuer(), 'suite_subject' => $identity['uuid'], 'role' => $user->role === 'owner' ? 'owner' : 'admin', 'local_login_enabled' => false, 'must_change_password' => false, 'access_revision' => $user->access_revision + 1, 'last_login_at' => now()])->save();

            return $user;
        });
        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('user_access_version', $user->access_version);
        Audit::record($user->id, 'auth.suite.login', 'Colaborador autenticado pela Suíte com perfil de administrador.');

        return redirect()->intended(route('workspace'));
    }

    private function limited(Request $request, int $seconds): RedirectResponse
    {
        $seconds = max(1, $seconds);

        return redirect()->back()->withErrors(['email' => 'Aguarde '.$seconds.' segundos antes de tentar entrar pela Suíte novamente.'])->withInput($request->only('email'))->header('Retry-After', (string) $seconds);
    }
}
