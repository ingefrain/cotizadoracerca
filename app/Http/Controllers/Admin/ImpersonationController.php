<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ImpersonationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, ImpersonationManager $impersonation): RedirectResponse
    {
        $impersonation->start($request, $user);

        return redirect()->route('admin.profile.edit')
            ->with('info', 'Ahora estás actuando como '.$user->name.'. La acción quedó registrada en auditoría.');
    }

    public function stop(Request $request, ImpersonationManager $impersonation): RedirectResponse
    {
        abort_unless($impersonation->active(), 404);

        $actor = $impersonation->finish($request, 'manual');
        if (! $actor) {
            return redirect()->route('admin.login')->with('warning', 'La sesión administrativa ya no está disponible.');
        }

        return redirect()->route('admin.users.index')->with('status', 'Volviste a tu sesión administrativa.');
    }
}
