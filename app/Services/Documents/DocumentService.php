<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use App\Support\AdminAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentService
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly ManagedDocumentPath $managedPath,
        private readonly DocumentImageProcessor $imageProcessor,
    ) {}

    /** @param array<string, mixed> $attributes @param array<string, mixed>|null $metadata */
    public function upload(
        UploadedFile $file,
        Model $documentable,
        User $uploader,
        string $category,
        array $attributes = [],
        ?array $metadata = null,
    ): Document {
        $category = $this->validateCategory($category);
        $prepared = $this->prepareFile($file, $category);
        $metadata = array_merge($metadata ?? [], $prepared['processing_metadata'] ?? []);
        $this->validateMetadata($metadata);
        $disk = $this->privateDisk();
        $uuid = (string) Str::uuid();
        $storedName = $uuid.'.'.$prepared['extension'];
        $path = trim((string) config('documents.directory', 'documents'), '/').'/'.$storedName;
        $this->storeOrFail($disk, $path, $file, $prepared['contents'] ?? null);

        try {
            return DB::transaction(function () use ($prepared, $documentable, $uploader, $category, $attributes, $metadata, $disk, $path, $storedName, $uuid): Document {
                $document = new Document;
                $document->uuid = $uuid;
                $document->documentable()->associate($documentable);
                $document->category = $category;
                $document->original_name = $prepared['original_name'];
                $document->stored_name = $storedName;
                $document->disk = $disk;
                $document->path = $path;
                $document->mime_type = $prepared['mime_type'];
                $document->extension = $prepared['extension'];
                $document->size_bytes = $prepared['size_bytes'];
                $document->checksum = $prepared['checksum'];
                $document->title = $this->optionalString($attributes['title'] ?? null, 200, 'title');
                $document->description = $this->optionalString($attributes['description'] ?? null, 4000, 'description');
                $document->uploader()->associate($uploader);
                $document->metadata = $metadata;
                $document->save();

                $this->audit->record('document.uploaded', 'document', $document->uuid, null, [
                    'category' => $category,
                    'mime_type' => $prepared['mime_type'],
                    'size_bytes' => $prepared['size_bytes'],
                ], actorId: $uploader->id);

                return $document;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFile($disk, $path);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed>|null $metadata */
    public function update(
        Document $document,
        array $attributes,
        User $actor,
        ?UploadedFile $replacement = null,
        ?array $metadata = null,
    ): Document {
        $category = $this->validateCategory((string) ($attributes['category'] ?? $document->category));
        $title = array_key_exists('title', $attributes)
            ? $this->optionalString($attributes['title'], 200, 'title')
            : $document->title;
        $description = array_key_exists('description', $attributes)
            ? $this->optionalString($attributes['description'], 4000, 'description')
            : $document->description;
        $this->validateMetadata($metadata);
        $prepared = $replacement ? $this->prepareFile($replacement, $category) : null;
        if ($prepared !== null && isset($prepared['processing_metadata'])) {
            $metadata = array_merge(is_array($document->metadata) ? $document->metadata : [], $metadata ?? [], $prepared['processing_metadata']);
            $this->validateMetadata($metadata);
        }
        $newPath = null;
        $newName = null;

        if ($prepared !== null) {
            $this->assertPrivateDisk($document->disk);
            if (! $this->managedPath->matches($document)) {
                throw ValidationException::withMessages(['file' => 'No se pudo verificar la ubicación actual del documento.']);
            }
            $newName = Str::uuid().'.'.$prepared['extension'];
            $newPath = trim((string) config('documents.directory', 'documents'), '/').'/'.$newName;
            $this->storeOrFail($document->disk, $newPath, $replacement, $prepared['contents'] ?? null);
        }

        $oldDisk = $document->disk;
        $oldPath = $document->path;
        try {
            $updated = DB::transaction(function () use ($document, $actor, $category, $title, $description, $metadata, $prepared, $newPath, $newName): Document {
                $beforeFields = [
                    'category' => $document->category,
                    'title' => $document->title,
                    'description' => $document->description,
                ];
                $document->category = $category;
                $document->title = $title;
                $document->description = $description;
                if ($metadata !== null) {
                    $document->metadata = $metadata;
                }
                if ($prepared !== null) {
                    $document->original_name = $prepared['original_name'];
                    $document->stored_name = $newName;
                    $document->path = $newPath;
                    $document->mime_type = $prepared['mime_type'];
                    $document->extension = $prepared['extension'];
                    $document->size_bytes = $prepared['size_bytes'];
                    $document->checksum = $prepared['checksum'];
                }
                $document->save();
                $this->audit->record(
                    $prepared !== null ? 'document.replaced' : 'document.updated',
                    'document',
                    $document->uuid,
                    ['changed_fields' => array_values(array_filter(
                        ['category', 'title', 'description'],
                        fn (string $key): bool => $beforeFields[$key] !== $document->{$key},
                    ))],
                    ['file_replaced' => $prepared !== null, 'category' => $category],
                    actorId: $actor->id,
                );

                return $document->fresh();
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $this->deleteStoredFile($oldDisk, $newPath);
            }
            throw $exception;
        }

        if ($newPath !== null && $oldPath !== $newPath) {
            $this->deleteStoredFile($oldDisk, $oldPath);
        }

        return $updated;
    }

    public function delete(Document $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor): void {
            $document->delete();
            $this->audit->record('document.deleted', 'document', $document->uuid, null, [
                'category' => $document->category,
                'file_retained' => true,
            ], actorId: $actor->id);
        });
    }

    /** @return array{original_name: string, mime_type: string, extension: string, size_bytes: int, checksum: string, contents?: string, processing_metadata?: array<string, int|string|bool>} */
    private function prepareFile(UploadedFile $file, string $category): array
    {
        if (! $file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessages(['file' => 'No se pudo recibir el archivo. Intenta subirlo nuevamente.']);
        }

        $size = $file->getSize();
        $categoryRules = (array) config('documents.category_rules', []);
        $rules = is_array($categoryRules[$category] ?? null) ? $categoryRules[$category] : [];
        $maximum = max(1, (int) ($rules['max_size_kb'] ?? config('documents.max_size_kb', 10240))) * 1024;
        if (! is_int($size) || $size <= 0 || $size > $maximum) {
            throw ValidationException::withMessages(['file' => 'El archivo está vacío o supera el tamaño máximo permitido.']);
        }

        $original = $this->safeIncomingName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $blocked = array_map('strtolower', array_merge(
            (array) config('documents.blocked_extensions', []),
            (array) ($rules['blocked_extensions'] ?? []),
        ));
        if ($extension === '' || in_array($extension, $blocked, true)) {
            throw ValidationException::withMessages(['file' => 'La extensión del archivo no está permitida.']);
        }

        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! is_file($realPath)) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo recibido.']);
        }
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $fileInfo->file($realPath);
        if (! is_string($detectedMime) || $detectedMime === '') {
            throw ValidationException::withMessages(['file' => 'No se pudo detectar el tipo del archivo.']);
        }
        $mime = strtolower($detectedMime);
        $allowed = (array) ($rules['allowed_mime_types'] ?? config('documents.allowed_mime_types', []));
        if (! isset($allowed[$mime]) || ! in_array($extension, array_map('strtolower', (array) $allowed[$mime]), true)) {
            throw ValidationException::withMessages(['file' => 'El tipo MIME y la extensión del archivo no están permitidos.']);
        }

        $processed = $this->imageProcessor->process($file, $category, $mime);
        if ($processed !== null) {
            $mime = $processed['mime_type'];
            $extension = $processed['extension'];
            $size = strlen($processed['contents']);
            $checksum = hash('sha256', $processed['contents']);
            $processingMetadata = [
                'source_mime' => strtolower($detectedMime),
                'optimized' => true,
                'width' => $processed['width'],
                'height' => $processed['height'],
            ];
        } else {
            $checksum = hash_file('sha256', $realPath);
            $processingMetadata = null;
        }
        if (! is_string($checksum)) {
            throw ValidationException::withMessages(['file' => 'No se pudo verificar la integridad del archivo.']);
        }

        $prepared = [
            'original_name' => $original,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => $size,
            'checksum' => $checksum,
        ];
        if ($processed !== null) {
            $prepared['contents'] = $processed['contents'];
            $prepared['processing_metadata'] = $processingMetadata;
        }

        return $prepared;
    }

    private function safeIncomingName(string $name): string
    {
        if ($name === '' || strpbrk($name, '/\\') !== false || preg_match('/[\x00-\x1F\x7F]/u', $name) || str_contains($name, '..') || str_starts_with($name, '.')) {
            throw ValidationException::withMessages(['file' => 'El nombre del archivo no es válido.']);
        }

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || strlen($name) > 255 || pathinfo($name, PATHINFO_BASENAME) !== $name) {
            throw ValidationException::withMessages(['file' => 'El nombre del archivo no es válido.']);
        }

        return $name;
    }

    private function validateCategory(string $category): string
    {
        $category = trim($category);
        if ($category === '' || strlen($category) > 100 || ! preg_match('/^[\pL\pN][\pL\pN._ -]*$/u', $category)) {
            throw ValidationException::withMessages(['category' => 'La categoría debe ser un texto breve sin separadores de ruta.']);
        }

        return $category;
    }

    private function optionalString(mixed $value, int $max, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => 'El valor es demasiado largo o contiene caracteres no permitidos.']);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            throw ValidationException::withMessages([$field => 'El valor es demasiado largo o contiene caracteres no permitidos.']);
        }

        return $value;
    }

    /** @param array<string, mixed>|null $metadata */
    private function validateMetadata(?array $metadata): void
    {
        if ($metadata === null) {
            return;
        }
        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['metadata' => 'La metadata no contiene JSON válido.']);
        }
        if (strlen($json) > (int) config('documents.metadata_max_bytes', 8192) || ! $this->metadataValuesAreSafe($metadata, 0)) {
            throw ValidationException::withMessages(['metadata' => 'La metadata excede el tamaño o contiene campos no permitidos.']);
        }
    }

    /** @param array<mixed> $values */
    private function metadataValuesAreSafe(array $values, int $depth): bool
    {
        if ($depth > 5) {
            return false;
        }
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '');
                if (in_array($normalizedKey, ['key', 'privatekey', 'accesskey', 'apikey', 'authkey', 'signingkey'], true)
                    || preg_match('/password|secret|token|credential/i', $normalizedKey)) {
                    return false;
                }
            }
            if (is_array($value)) {
                if (! $this->metadataValuesAreSafe($value, $depth + 1)) {
                    return false;
                }
            } elseif (! is_null($value) && ! is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    private function privateDisk(): string
    {
        return $this->assertPrivateDisk((string) config('documents.disk', 'local'));
    }

    private function assertPrivateDisk(string $disk): string
    {
        $settings = config("filesystems.disks.{$disk}", []);
        if ($disk === '' || $disk === 'public' || ! is_array($settings) || strtolower((string) ($settings['visibility'] ?? 'private')) === 'public') {
            throw ValidationException::withMessages(['file' => 'El disco configurado para documentos debe ser privado.']);
        }

        return $disk;
    }

    private function storeOrFail(string $disk, string $path, UploadedFile $file, ?string $contents = null): void
    {
        try {
            $stored = $contents === null
                ? Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])
                : Storage::disk($disk)->put($path, $contents, ['visibility' => 'private']);
            if (! $stored) {
                $this->deleteStoredFile($disk, $path);
                throw ValidationException::withMessages(['file' => 'No se pudo almacenar el archivo.']);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            $this->deleteStoredFile($disk, $path);
            throw ValidationException::withMessages(['file' => 'No se pudo almacenar el archivo.']);
        }
    }

    private function deleteStoredFile(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Storage auditing can report any file left behind after a failed compensation.
        }
    }
}
