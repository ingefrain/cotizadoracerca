<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Services\Documents\DocumentOwnerAccess;
use App\Services\Documents\DocumentService;
use App\Services\Documents\ManagedDocumentPath;
use App\Support\ImpersonationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class DocumentController extends Controller
{
    public function index(Request $request, DocumentOwnerAccess $ownerAccess): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['image', 'pdf', 'text', 'other'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $query = $ownerAccess->scopeVisibleTo(Document::query(), $request->user())
            ->with(['documentable', 'uploader'])
            ->when($filters['q'] ?? null, fn ($builder, string $term) => $builder->search(trim($term)))
            ->when($filters['category'] ?? null, fn ($builder, string $category) => $builder->where('category', $category))
            ->when($filters['date_from'] ?? null, fn ($builder, string $date) => $builder->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($builder, string $date) => $builder->whereDate('created_at', '<=', $date));

        if (($filters['type'] ?? null) !== null) {
            match ($filters['type']) {
                'image' => $query->where('mime_type', 'like', 'image/%'),
                'pdf' => $query->where('mime_type', 'application/pdf'),
                'text' => $query->where('mime_type', 'like', 'text/%'),
                'other' => $query->where('mime_type', 'not like', 'image/%')->where('mime_type', 'not like', 'text/%')->where('mime_type', '<>', 'application/pdf'),
            };
        }

        $documents = $query->latest('created_at')->paginate(20)->withQueryString();
        $documents->getCollection()->each(function (Document $document): void {
            $document->setAttribute('entity_label', $this->entityLabel($document));
        });
        $categories = $ownerAccess->scopeVisibleTo(Document::query(), $request->user())
            ->select('category')->distinct()->orderBy('category')->pluck('category');

        $labels = (array) config('documents.category_labels', []);
        $documents->getCollection()->each(function (Document $document) use ($labels): void {
            $document->setAttribute('category_label', $labels[$document->category] ?? $document->category);
        });

        $categories = $categories->map(fn (string $category): array => [
            'key' => $category,
            'label' => $labels[$category] ?? $category,
        ]);

        return view('admin.documents.index', compact('documents', 'filters', 'categories'));
    }

    public function store(Request $request, DocumentService $documents, ImpersonationManager $impersonation): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'category' => ['required', 'string', 'max:100', Rule::notIn([CatalogItem::IMAGE_CATEGORY])],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);
        $document = $documents->upload(
            $request->file('file'),
            $request->user(),
            $impersonation->actor() ?? $request->user(),
            $validated['category'],
            $validated,
        );

        return redirect()->route('admin.documents.index')->with('status', 'Documento almacenado de forma privada.');
    }

    public function update(Request $request, Document $document, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('update', $document);
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:100', Rule::notIn([CatalogItem::IMAGE_CATEGORY])],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'file' => ['nullable', 'file'],
        ]);
        $documents->update(
            $document,
            $validated,
            $request->user(),
            $request->file('file'),
        );

        return redirect()->route('admin.documents.index')->with('status', 'Documento actualizado.');
    }

    public function destroy(Request $request, Document $document, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('delete', $document);
        $documents->delete($document, $request->user());

        return redirect()->route('admin.documents.index')->with('status', 'Documento marcado para eliminación.');
    }

    public function download(Document $document, ManagedDocumentPath $managedPath)
    {
        Gate::authorize('download', $document);
        if (! $managedPath->matches($document)) {
            abort(404);
        }

        try {
            $disk = Storage::disk($document->disk);
            abort_unless($disk->exists($document->path), 404);

            return $disk->download($document->path, $document->safeOriginalName(), [
                'Content-Type' => $document->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (Throwable) {
            abort(404);
        }
    }

    private function entityLabel(Document $document): string
    {
        $owner = $document->documentable;
        if ($owner === null) {
            return 'Entidad no disponible';
        }

        $label = $owner->getAttribute('name') ?: $owner->getAttribute('title');

        if ($owner instanceof Organization) {
            return 'Organización — '.(string) $owner->name;
        }
        if ($owner instanceof Location) {
            return 'Sede — '.(string) $owner->name;
        }

        return class_basename($owner).' — '.(is_string($label) && $label !== '' ? $label : 'Registro asociado');
    }
}
