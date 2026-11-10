<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\AdminLoginLimiter;
use App\Services\LoginAttemptRecorder;
use App\Support\ImpersonationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return view('admin.auth.login');
        }

        $route = request()->user()->can('dashboard.view') ? 'admin.dashboard' : 'admin.profile.edit';

        return redirect()->route($route);
    }

    public function store(Request $request, LoginAttemptRecorder $recorder, AdminLoginLimiter $limiter): RedirectResponse|Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $credentials['email'] = mb_strtolower(trim($credentials['email']));
        $remember = $request->boolean('remember');
        unset($credentials['remember']);

        $email = $credentials['email'];
        $ip = (string) $request->ip();
        $retryAfter = $limiter->retryAfter($email, $ip);
        if ($retryAfter > 0) {
            $recorder->record($request, LoginAttempt::RATE_LIMITED, 'rate_limit_exceeded');

            return response()->view('admin.auth.login', ['retryAfter' => $retryAfter, 'loginEmail' => $email], 429, [
                'Retry-After' => (string) $retryAfter,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }

        $attemptedUser = User::query()->select(['id', 'email', 'is_active'])->where('email', $credentials['email'])->first();

        if (! Auth::attempt([...$credentials, 'is_active' => true], $remember)) {
            $limiter->recordFailure($email, $ip);
            $inactive = $attemptedUser !== null && ! $attemptedUser->is_active;
            $recorder->record(
                $request,
                $inactive ? LoginAttempt::INACTIVE_ACCOUNT : LoginAttempt::INVALID_CREDENTIALS,
                $inactive ? 'account_inactive' : 'credentials_rejected',
                $attemptedUser,
            );

            throw ValidationException::withMessages(['email' => 'El correo o la contraseña no son correctos.']);
        }

        $user = $request->user();
        $limiter->clearIdentity($email, $ip);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $recorder->record($request, LoginAttempt::SUCCESS, 'authenticated', $user);

        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $request->user()->session_version);

        $fallback = $request->user()->can('dashboard.view')
            ? route('admin.dashboard')
            : route('admin.profile.edit');

        return redirect()->intended($fallback);
    }

    public function destroy(Request $request, ImpersonationManager $impersonation): RedirectResponse
    {
        if ($impersonation->active()) {
            $impersonation->finish($request, 'logout', false);
        }

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Sesión cerrada correctamente.');
    }
}
