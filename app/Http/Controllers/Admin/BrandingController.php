<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationBranding;
use App\Services\Documents\DocumentService;
use App\Services\Documents\ManagedDocumentPath;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingController extends Controller
{
    public function edit(): View
    {
        return view('admin.branding', ['branding' => ApplicationBranding::current()]);
    }

    public function update(Request $request, DocumentService $documents, AdminAuditLogger $audit, ImpersonationManager $impersonation): RedirectResponse
    {
        $validated = $request->validate([
            'visible_name' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'file'],
        ]);
        $branding = ApplicationBranding::query()->findOrFail(1);
        $before = $branding->visible_name;
        $branding->visible_name = filled($validated['visible_name'] ?? null) ? trim($validated['visible_name']) : null;
        $branding->save();
        if ($before !== $branding->visible_name) {
            $audit->record('branding.name_updated', 'application_branding', '1', ['visible_name' => $before], ['visible_name' => $branding->visible_name]);
        }
        if ($request->hasFile('logo')) {
            $actor = $impersonation->actor() ?? $request->user();
            $existing = $branding->logo;
            if ($existing) {
                $documents->update($existing, [], $actor, $request->file('logo'));
            } else {
                $documents->upload($request->file('logo'), $branding, $actor, 'branding.logo', ['title' => 'Logotipo del sistema']);
            }
        }

        return back()->with('status', 'Identidad visual actualizada.');
    }

    public function destroyLogo(Request $request, DocumentService $documents): RedirectResponse
    {
        $logo = ApplicationBranding::query()->findOrFail(1)->logo;
        abort_unless($logo, 404);
        $documents->delete($logo, $request->user());

        return back()->with('status', 'Logotipo eliminado.');
    }

    public function logo(Request $request, ManagedDocumentPath $paths): StreamedResponse
    {
        $logo = ApplicationBranding::current()->logo;
        abort_unless($logo, 404);

        return AvatarController::response($logo, $paths, true, $request);
    }
}
