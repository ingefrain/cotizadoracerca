<?php

namespace App\Http\Controllers\Admin;

use App\Models\Catalog;
use App\Models\Organization;
use App\Policies\CatalogPolicy;
use App\Support\AdminAuditLogger;
use App\Support\CatalogFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CatalogController
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Catalog::class);
        $organizations = $this->organizations($request);
        $filters = CatalogFields::filters($request, ['organization' => ['nullable', 'uuid']]);
        $catalogs = Catalog::query()->with('organization')->withCount('items')
            ->when($organizations === null, fn ($query) => $query->whereNull('organization_id'))
            ->when(isset($filters['organization']), fn ($query) => $query->whereHas('organization', fn ($org) => $org->where('uuid', $filters['organization'])))
            ->when(isset($filters['q']), fn ($query) => $query->whereLike('name', '%'.$filters['q'].'%'))
            ->when(isset($filters['status']), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(15)->withQueryString();

        return view('admin.catalogs.index', compact('catalogs', 'organizations', 'filters'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Catalog::class);

        return $this->form($request, new Catalog(['is_active' => true, 'sort_order' => 0]));
    }

    public function store(Request $request, AdminAuditLogger $audit): RedirectResponse
    {
        Gate::authorize('create', Catalog::class);
        $data = $this->validated($request, new Catalog);
        $catalog = DB::transaction(function () use ($data, $audit): Catalog {
            $catalog = Catalog::create($data);
            $audit->record('catalog.created', 'catalog', $catalog->uuid, after: $catalog->only(['slug', 'is_active', 'sort_order']));

            return $catalog;
        });

        return redirect()->route('admin.catalogs.edit', $catalog)
            ->with('notify', ['type' => 'success', 'message' => 'Catálogo creado correctamente.']);
    }

    public function edit(Request $request, Catalog $catalog): View
    {
        Gate::authorize('view', $catalog);

        return $this->form($request, $catalog);
    }

    public function update(Request $request, Catalog $catalog, AdminAuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $catalog);
        $data = $this->validated($request, $catalog);
        DB::transaction(function () use ($catalog, $data, $audit): void {
            $locked = Catalog::query()->lockForUpdate()->findOrFail($catalog->id);
            Gate::authorize('update', $locked);
            $locked->fill($data);
            $fields = array_keys($locked->getDirty());
            if ($fields !== []) {
                $locked->save();
                $audit->record('catalog.updated', 'catalog', $locked->uuid, after: ['changed_fields' => $fields]);
            }
        });

        return back()->with('notify', ['type' => 'success', 'message' => 'Catálogo actualizado.']);
    }

    public function status(Request $request, Catalog $catalog, AdminAuditLogger $audit): RedirectResponse|JsonResponse
    {
        Gate::authorize('disable', $catalog);
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        DB::transaction(function () use ($catalog, $active, $audit): void {
            $locked = Catalog::query()->lockForUpdate()->findOrFail($catalog->id);
            Gate::authorize('disable', $locked);
            if ($locked->is_active !== $active) {
                $locked->update(['is_active' => $active]);
                $audit->record($active ? 'catalog.enabled' : 'catalog.disabled', 'catalog', $locked->uuid, ['is_active' => ! $active], ['is_active' => $active]);
            }
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'is_active' => $active, 'label' => $active ? 'Activo' : 'Inactivo']);
        }

        return back()->with('notify', ['type' => 'success', 'message' => $active ? 'Catálogo activado.' : 'Catálogo desactivado.']);
    }

    private function form(Request $request, Catalog $catalog): View
    {
        return view('admin.catalogs.form', ['catalog' => $catalog->load('organization'), 'organizations' => $this->organizations($request)]);
    }

    private function organizations(Request $request): ?Collection
    {
        return CatalogPolicy::permits($request->user(), 'organizations.view')
            ? Organization::query()->orderBy('name')->get(['id', 'uuid', 'name', 'is_active'])
            : null;
    }

    private function validated(Request $request, Catalog $catalog): array
    {
        if ($request->filled('organization')) {
            abort_unless(CatalogPolicy::permits($request->user(), 'organizations.view'), 403);
        }

        $data = CatalogFields::validate($request, Rule::unique('catalogs', 'slug')->ignore($catalog->id), [
            'organization' => ['nullable', 'uuid', 'exists:organizations,uuid'],
            ...($catalog->exists ? [] : ['is_active' => ['required', 'boolean']]),
        ]);
        $data['organization_id'] = isset($data['organization'])
            ? Organization::query()->where('uuid', $data['organization'])->firstOrFail()->id
            : null;
        $data['sort_order'] ??= 0;
        unset($data['organization']);

        return $data;
    }
}
