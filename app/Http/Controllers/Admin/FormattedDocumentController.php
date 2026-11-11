<?php

namespace App\Http\Controllers\Admin;

use App\Models\FormattedDocument;
use App\Support\AdminAuditLogger;
use App\Support\FormattedDocumentAccess;
use App\Support\ImpersonationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class FormattedDocumentController
{
    public function __construct(private readonly FormattedDocumentAccess $access) {}

    public function index(Request $request): Response
    {
        $this->authorize($request, 'view');
        $documents = FormattedDocument::query()->select(['id', 'uuid', 'title', 'created_by', 'created_at', 'updated_at'])
            ->with('author:id,name')->orderByDesc('updated_at')->orderByDesc('id')->paginate(15);

        return response()->view('admin.pdf.index', compact('documents'), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, 'create');

        return $this->editor(new FormattedDocument);
    }

    public function show(Request $request, FormattedDocument $document): Response
    {
        $this->authorize($request, 'view');
        $document->load(['author', 'updater']);

        return response()->view('admin.pdf.show', compact('document'), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function edit(Request $request, FormattedDocument $document): Response
    {
        $this->authorize($request, 'update');

        return $this->editor($document);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request, 'create');

        return $this->save($request, new FormattedDocument);
    }

    public function update(Request $request, FormattedDocument $document): JsonResponse
    {
        $this->authorize($request, 'update');

        return $this->save($request, $document);
    }

    private function authorize(Request $request, string $operation): void
    {
        abort_unless($this->access->allows($request->user(), $operation), 403);
    }

    private function editor(FormattedDocument $document): Response
    {
        return response()->view('admin.pdf.editor', compact('document'), 200, ['Cache-Control' => 'private, no-store']);
    }

    private function save(Request $request, FormattedDocument $document): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->json(['message' => 'Envía los campos del borrador como JSON.'], 415);
        }
        // Read original JSON bytes: global TrimStrings has already normalized request input.
        // Never flash the request or pass a validation exception containing the document.
        $data = json_decode($request->getContent(), true);
        if (! is_array($data) || array_is_list($data)) {
            return $this->json(['message' => 'El borrador debe contener JSON válido.'], 422);
        }
        $text = static function ($attribute, $value, $fail): void {
            if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value))) {
                $fail('Utiliza texto UTF-8 sin caracteres de control.');
            }
        };
        $validator = Validator::make($data, [
            'title' => ['bail', 'required', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'],
            'body_markdown' => ['bail', 'required', 'string', $text, static function ($attribute, $value, $fail): void {
                if (strlen($value) > (int) config('pdf.preview_max_bytes')) {
                    $fail('El Markdown supera el límite de 100 KiB.');
                }
            }],
            'notes' => ['bail', 'nullable', 'string', 'max:5000', $text],
        ], [
            'title.required' => 'Escribe un título.', 'title.max' => 'El título admite hasta 200 caracteres.',
            'body_markdown.required' => 'Escribe el contenido Markdown.',
            'notes.max' => 'Las notas admiten hasta 5000 caracteres.',
        ]);
        if ($validator->fails()) {
            return $this->json(['message' => 'Revisa los campos indicados. El contenido se conserva.', 'errors' => $validator->errors()], 422);
        }
        $attributes = $validator->validated();
        $attributes['notes'] ??= null;
        $creating = ! $document->exists;
        try {
            $document = DB::transaction(function () use ($document, $attributes, $creating): FormattedDocument {
                if (! $creating) {
                    $document = FormattedDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                }
                $document->fill($attributes);
                $changed = array_keys($document->getDirty());
                $actor = app(ImpersonationManager::class)->actor();
                if ($creating) {
                    $document->created_by = $actor->id;
                } elseif ($changed !== []) {
                    $document->updated_by = $actor->id;
                }
                if ($creating || $changed !== []) {
                    $document->save();
                    app(AdminAuditLogger::class)->record(
                        $creating ? 'formatted_document.created' : 'formatted_document.updated',
                        'formatted_document', $document->uuid,
                        context: ['changed_fields' => $changed],
                    );
                }

                return $document;
            });
        } catch (Throwable $exception) {
            // Query exceptions can include Markdown in SQL bindings. Never log them verbatim.
            Log::warning('Draft persistence failed.', ['exception_type' => $exception::class]);

            return $this->json(['message' => 'No fue posible guardar. Tu contenido se conserva; inténtalo de nuevo.'], 503);
        }

        return $this->json([
            'message' => 'Borrador guardado.', 'uuid' => $document->uuid,
            'edit_url' => route($this->access->allows($request->user(), 'update') ? 'admin.pdf.edit' : 'admin.pdf.show', $document),
            'update_url' => route('admin.pdf.update', $document),
            'can_update' => $this->access->allows($request->user(), 'update'),
        ], $creating ? 201 : 200);
    }

    private function json(array $data, int $status): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
