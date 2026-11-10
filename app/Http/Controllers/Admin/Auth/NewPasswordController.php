<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminAuditLogger;
use App\Support\PasswordPolicy;
use App\Support\SessionInvalidator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return response()->view('admin.auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function store(
        Request $request,
        SessionInvalidator $sessions,
        AdminAuditLogger $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => PasswordPolicy::rules(),
        ]);
        $validated['email'] = mb_strtolower(trim($validated['email']));

        $status = DB::transaction(function () use ($validated, $sessions, $audit): string {
            return Password::reset($validated, function (User $user, string $password) use ($sessions, $audit): void {
                if (! $user->is_active) {
                    throw ValidationException::withMessages(['email' => 'La cuenta no está disponible.']);
                }

                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $sessions->invalidate([$user->id]);
                $audit->record('user.password_reset', 'user', $user->id, null, null, ['source' => 'password_recovery']);
                event(new PasswordReset($user));
            });
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('admin.login')->with('status', 'La contraseña se actualizó. Inicia sesión con tu nueva contraseña.')
            : back()->withErrors(['email' => __($status)]);
    }
}
