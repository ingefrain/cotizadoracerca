<?php

namespace App\Http\Middleware;

use App\Support\ImpersonationManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $sessionVersion = $request->session()->get('auth_session_version');

        if ($user && app(ImpersonationManager::class)->active()) {
            $request->session()->put('auth_session_version', $user->session_version);

            return $next($request);
        }

        if ($user && $sessionVersion !== null && (int) $sessionVersion !== $user->session_version) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'Tu sesión cambió. Inicia sesión de nuevo.']);
        }

        if ($user && $sessionVersion === null) {
            $request->session()->put('auth_session_version', $user->session_version);
        }

        return $next($request);
    }
}
