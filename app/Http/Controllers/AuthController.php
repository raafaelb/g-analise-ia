<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\LocalPreview;
use App\Services\SuiteIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function home(LocalPreview $preview): RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/app');
        }
        if ($preview->enabled()) {
            $preview->signIn();

            return redirect('/app');
        }

        return redirect('/login');
    }

    public function form(): Response
    {
        return Inertia::render('Auth', ['canRegister' => config('horus.registration') || (app()->environment('local') && User::count() === 0), 'suiteConfigured' => app(SuiteIdentity::class)->configured()]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'login:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Aguarde um minuto antes de tentar novamente.']);
        }
        if (! Auth::attempt([...$data, 'active' => true, 'local_login_enabled' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('user_access_version', $request->user()->access_version);
        $request->user()->forceFill(['last_login_at' => now()])->save();
        Audit::record($request->user()->id, 'auth.login', 'Acesso à plataforma.');

        return $request->user()->must_change_password ? redirect()->route('workspace', ['section' => 'account']) : redirect()->intended('/app');
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless(config('horus.registration') || (app()->environment('local') && User::count() === 0), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'unique:users'], 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers(), 'max:64', 'regex:/\A.{0,72}\z/sD', 'not_regex:/\x00/']]);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        if (User::count() === 1) {
            $user->forceFill(['role' => 'owner'])->save();
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('user_access_version', $request->user()->access_version);
        Audit::record($user->id, 'auth.register', 'Conta criada.');

        return redirect('/app');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
