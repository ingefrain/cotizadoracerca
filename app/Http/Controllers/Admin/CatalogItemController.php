<?php

namespace App\Http\Controllers\Admin;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Services\Documents\DocumentService;
use App\Services\Documents\ManagedDocumentPath;
use App\Support\AdminAuditLogger;
use App\Support\CatalogFields;
use App\Support\ImpersonationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CatalogItemController
{
    public function index(Request $request, Catalog $catalog): View
    {
        Gate::authorize('viewAny', [CatalogItem::class, $catalog]);
        $catalog->load('organization');
        $filters = CatalogFields::filters($request);
        $items = $catalog->items()->with('image')
            ->when(isset($filters['q']), fn ($query) => $query->where(fn ($search) => $search
                ->whereLike('name', '%'.$filters['q'].'%')->orWhereLike('code', '%'.$filters['q'].'%')))
            ->when(isset($filters['status']), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(15)->withQueryString();
        $items->getCollection()->each(fn (CatalogItem $item) => $item->setRelation('catalog', $catalog));

        return view('admin.catalogs.items.index', compact('catalog', 'filters', 'items'));
    }

    public function create(Catalog $catalog): View
    {
        Gate::authorize('create', [CatalogItem::class, $catalog]);

        return view('admin.catalogs.items.form', [
            'catalog' => $catalog,
            'item' => new CatalogItem(['is_active' => true, 'sort_order' => 0]),
        ]);
    }

    public function store(Request $request, Catalog $catalog, AdminAuditLogger $audit, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('create', [CatalogItem::class, $catalog]);
        $data = $this->validated($request, $catalog, new CatalogItem);
        $item = DB::transaction(function () use ($request, $catalog, $data, $audit, $documents): CatalogItem {
            $parent = Catalog::query()->lockForUpdate()->findOrFail($catalog->id);
            Gate::authorize('create', [CatalogItem::class, $parent]);
            $item = $parent->items()->create($data);
            $audit->record('catalog_item.created', 'catalog_item', $item->uuid, after: [
                'catalog' => $parent->uuid, 'is_active' => $item->is_active, 'sort_order' => $item->sort_order,
            ]);
            $this->replaceImage($request, $item, $documents);

            return $item;
        });

        return redirect()->route('admin.catalogs.items.edit', [$catalog, $item])
            ->with('notify', ['type' => 'success', 'message' => 'Elemento creado correctamente.']);
    }

    public function edit(Catalog $catalog, CatalogItem $item): View
    {
        Gate::authorize('view', $item);
        $item->load('image');

        return view('admin.catalogs.items.form', compact('catalog', 'item'));
    }

    public function update(Request $request, Catalog $catalog, CatalogItem $item, AdminAuditLogger $audit, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('update', $item);
        $data = $this->validated($request, $catalog, $item);
        DB::transaction(function () use ($request, $catalog, $item, $data, $audit, $documents): void {
            $locked = $this->lockItem($catalog, $item, 'update');
            $locked->fill($data);
            $fields = array_keys($locked->getDirty());
            if ($request->hasFile('image')) {
                $fields[] = 'image';
            }
            if ($fields !== []) {
                $locked->save();
                $audit->record('catalog_item.updated', 'catalog_item', $locked->uuid, after: ['changed_fields' => $fields]);
            }
            $this->replaceImage($request, $locked, $documents);
        });

        return back()->with('notify', ['type' => 'success', 'message' => 'Elemento actualizado.']);
    }

    public function status(Request $request, Catalog $catalog, CatalogItem $item, AdminAuditLogger $audit): RedirectResponse|JsonResponse
    {
        Gate::authorize('disable', $item);
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        DB::transaction(function () use ($catalog, $item, $active, $audit): void {
            $locked = $this->lockItem($catalog, $item, 'disable');
            if ($locked->is_active !== $active) {
                $locked->update(['is_active' => $active]);
                $audit->record($active ? 'catalog_item.enabled' : 'catalog_item.disabled', 'catalog_item', $locked->uuid, ['is_active' => ! $active], ['is_active' => $active]);
            }
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'is_active' => $active, 'label' => $active ? 'Activo' : 'Inactivo']);
        }

        return back()->with('notify', ['type' => 'success', 'message' => $active ? 'Elemento activado.' : 'Elemento desactivado.']);
    }

    public function image(Request $request, Catalog $catalog, CatalogItem $item, ManagedDocumentPath $paths): StreamedResponse
    {
        Gate::authorize('view', $item);
        abort_unless($image = $item->image, 404);
        $response = AvatarController::response($image, $paths, false, $request, CatalogItem::IMAGE_CATEGORY);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function deleteImage(Request $request, Catalog $catalog, CatalogItem $item, DocumentService $documents, AdminAuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $item);
        DB::transaction(function () use ($request, $catalog, $item, $documents, $audit): void {
            $locked = $this->lockItem($catalog, $item, 'update');
            abort_unless($image = $locked->image, 404);
            $documents->delete($image, app(ImpersonationManager::class)->actor() ?? $request->user());
            $audit->record('catalog_item.updated', 'catalog_item', $locked->uuid, after: ['changed_fields' => ['image']]);
        });

        return back()->with('status', 'Imagen retirada.');
    }

    private function lockItem(Catalog $catalog, CatalogItem $item, string $ability): CatalogItem
    {
        $parent = Catalog::query()->lockForUpdate()->findOrFail($catalog->id);
        $locked = $parent->items()->lockForUpdate()->findOrFail($item->id);
        $locked->setRelation('catalog', $parent);
        Gate::authorize($ability, $locked);

        return $locked;
    }

    private function replaceImage(Request $request, CatalogItem $item, DocumentService $documents): void
    {
        if (! $request->hasFile('image')) {
            return;
        }

        $actor = app(ImpersonationManager::class)->actor() ?? $request->user();
        if ($item->image) {
            $documents->delete($item->image, $actor);
        }
        try {
            $documents->upload($request->file('image'), $item, $actor, CatalogItem::IMAGE_CATEGORY, ['title' => 'Imagen principal']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['image' => $exception->errors()['file'] ?? ['No se pudo procesar la imagen.']]);
        }
    }

    private function validated(Request $request, Catalog $catalog, CatalogItem $item): array
    {
        $unique = fn (string $column) => Rule::unique('catalog_items', $column)->where('catalog_id', $catalog->id)->ignore($item->id);
        $data = CatalogFields::validate($request, $unique('slug'), [
            'code' => ['nullable', 'string', 'max:100', $unique('code')],
            'metadata' => ['nullable', 'string', 'max:2048', 'json'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('documents.category_rules')[CatalogItem::IMAGE_CATEGORY]['max_size_kb']],
            ...($item->exists ? [] : ['is_active' => ['required', 'boolean']]),
        ]);
        if (array_key_exists('metadata', $data) && $data['metadata'] !== null) {
            $metadata = json_decode($data['metadata']);
            if (! $metadata instanceof \stdClass || count((array) $metadata) > 20) {
                throw ValidationException::withMessages(['metadata' => 'Usa un objeto JSON con hasta 20 claves.']);
            }
            foreach ((array) $metadata as $key => $value) {
                if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/iD', (string) $key)
                    || preg_match('/password|secret|token|credential|api_?key/i', (string) $key)
                    || (! is_scalar($value) && $value !== null)
                    || (is_float($value) && ! is_finite($value))
                    || (is_string($value) && mb_strlen($value) > 250)) {
                    throw ValidationException::withMessages(['metadata' => 'Usa claves simples y valores escalares breves, sin credenciales.']);
                }
            }
            $data['metadata'] = (array) $metadata ?: null;
        }
        $data['code'] ??= null;
        $data['sort_order'] ??= 0;
        unset($data['image']);

        return $data;
    }
}
