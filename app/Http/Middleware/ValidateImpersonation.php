<?php

namespace App\Http\Middleware;

use App\Support\ImpersonationManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateImpersonation
{
    public function __construct(private readonly ImpersonationManager $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $impersonation = $this->impersonation;
        if (! $impersonation->active()) {
            return $next($request);
        }

        $target = $impersonation->target();
        if (! $impersonation->actorSessionIsCurrent()) {
            $impersonation->finish($request, 'actor_invalid', false);

            return redirect()->route('admin.login')->with('warning', 'La sesión administrativa terminó porque el actor dejó de estar activo o autorizado.');
        }

        if (! $target || (int) $request->user()?->id !== (int) $target->id) {
            $impersonation->finish($request, 'target_unavailable');

            return redirect()->route('admin.dashboard')->with('warning', 'La cuenta objetivo ya no está disponible; volviste a tu sesión.');
        }

        if ($impersonation->expired()) {
            $impersonation->finish($request, 'expired');

            return redirect()->route('admin.dashboard')->with('warning', 'La impersonación expiró; volviste a tu sesión administrativa.');
        }

        $targetWasActive = $target->is_active;
        $response = $next($request);
        $target->refresh();
        if (! $target->exists || ($targetWasActive && ! $target->is_active)) {
            $impersonation->finish($request, 'target_deactivated');

            return redirect()->route('admin.dashboard')->with('warning', 'El usuario fue desactivado; la impersonación terminó y volviste a tu sesión.');
        }

        return $response;
    }
}
