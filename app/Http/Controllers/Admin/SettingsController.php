<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\ImportAccess;
use App\Models\Catalog;
use App\Support\ImpersonationManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(ImpersonationManager $impersonation, ImportAccess $imports): View
    {
        $cards = collect(config('admin_settings.cards', []))
            ->filter(fn (array $card): bool => $card['permission'] === 'catalogs.view'
                ? Gate::allows('viewAny', Catalog::class)
                : ($impersonation->active()
                    ? (bool) $impersonation->effectiveUser()?->hasPermissionTo($card['permission'])
                    : Gate::allows($card['permission'])))
            ->values();
        if ($impersonation->effectiveUser() && $imports->allows($impersonation->effectiveUser(), 'imports.view')) {
            $cards->push([
                'title' => 'Importación de datos',
                'description' => 'Valida archivos CSV y revisa tus corridas antes de aplicar cambios.',
                'route' => 'admin.imports.index',
                'action' => 'Abrir importaciones',
            ]);
        }

        abort_if($cards->isEmpty(), 403);

        return view('admin.settings.index', compact('cards'));
    }
}
