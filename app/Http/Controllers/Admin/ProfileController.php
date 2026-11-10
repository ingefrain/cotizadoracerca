<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentService;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('admin.profile');
    }

    public function updateAvatar(Request $request, DocumentService $documents, ImpersonationManager $impersonation): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $user = $request->user();
        $uploader = $impersonation->actor() ?? $user;
        $avatar = $user->avatar;
        if ($avatar) {
            $documents->update($avatar, [], $uploader, $request->file('file'));
        } else {
            $documents->upload($request->file('file'), $user, $uploader, 'user.avatar', ['title' => 'Foto de perfil']);
        }

        return back()->with('status', 'Foto de perfil actualizada.');
    }

    public function deleteAvatar(Request $request, DocumentService $documents): RedirectResponse
    {
        $avatar = $request->user()->avatar;
        abort_unless($avatar, 404);
        $documents->delete($avatar, $request->user());

        return back()->with('status', 'Foto de perfil eliminada.');
    }

    public function update(Request $request, AdminAuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $user = $request->user();
        $before = ['name' => $user->name];
        $user->update($validated);
        if ($before['name'] !== $user->name) {
            $audit->record('user.profile_updated', 'user', $user->id, $before, ['name' => $user->name]);
        }

        return back()->with('status', 'Perfil actualizado.');
    }

    public function updatePassword(Request $request, AdminAuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'password' => PasswordPolicy::rules(),
        ]);
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        DB::transaction(function () use ($user, $validated, $currentSessionId, $request, $audit): void {
            $user->forceFill([
                'password' => $validated['password'],
                'remember_token' => Str::random(60),
                'session_version' => $user->session_version + 1,
            ])->save();
            DB::table('sessions')->where('user_id', $user->id)->where('id', '<>', $currentSessionId)->delete();
            Auth::guard()->setUser($user);
            $request->session()->put('auth_session_version', $user->session_version);
            $request->session()->regenerate();
            $audit->record('user.password_changed', 'user', $user->id);
        });

        return back()->with('status', 'Contraseña actualizada; las demás sesiones se cerraron.');
    }
}
