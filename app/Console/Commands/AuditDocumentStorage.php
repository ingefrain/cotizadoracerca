<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditDocumentStorage extends Command
{
    protected $signature = 'documents:audit-storage {--limit= : Maximum database rows and stored objects to inspect}';

    protected $description = 'Inspecciona de forma acotada metadata y almacenamiento de documentos sin modificar archivos.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) ($this->option('limit') ?: config('documents.audit_scan_limit', 500))));
        $diskName = (string) config('documents.disk', 'local');
        $disk = Storage::disk($diskName);
        $directory = trim((string) config('documents.directory', 'documents'), '/');
        $paths = [];
        $missing = 0;
        $inconsistent = 0;
        $rows = 0;

        $managedPath = app(ManagedDocumentPath::class);
        Document::withTrashed()->orderBy('id')->limit($limit)->get()->each(function (Document $document) use ($diskName, $disk, $managedPath, &$paths, &$missing, &$inconsistent, &$rows): void {
            $rows++;
            if ($document->disk !== $diskName || ! $managedPath->matches($document)) {
                $inconsistent++;

                return;
            }
            $paths[$document->path] = true;
            try {
                if (! $disk->exists($document->path)) {
                    $missing++;
                } elseif ($disk->size($document->path) !== $document->size_bytes
                    || preg_match('/^[a-f0-9]{64}$/i', $document->checksum) !== 1
                    || ! $this->checksumMatches($disk, $document)) {
                    $inconsistent++;
                }
            } catch (Throwable) {
                $inconsistent++;
            }
        });

        $orphans = 0;
        $scannedFiles = 0;
        try {
            foreach ($disk->getDriver()->listContents($directory, true) as $item) {
                if (! $item->isFile()) {
                    continue;
                }
                if ($scannedFiles >= $limit) {
                    break;
                }
                $scannedFiles++;
                $path = $item->path();
                if (! isset($paths[$path]) && ! Document::withTrashed()->where('disk', $diskName)->where('path', $path)->exists()) {
                    $orphans++;
                }
            }
        } catch (Throwable) {
            $this->error('No se pudo completar el recorrido acotado del storage configurado.');

            return self::FAILURE;
        }

        $this->table(['Indicador', 'Cantidad'], [
            ['filas inspeccionadas', $rows],
            ['archivos inspeccionados', $scannedFiles],
            ['archivos faltantes', $missing],
            ['archivos sin fila', $orphans],
            ['metadata inconsistente', $inconsistent],
            ['límite por recorrido', $limit],
        ]);

        return self::SUCCESS;
    }

    private function checksumMatches(FilesystemAdapter $disk, Document $document): bool
    {
        if ($document->size_bytes > (int) config('documents.max_size_kb', 10240) * 1024) {
            return false;
        }

        $stream = $disk->readStream($document->path);
        if (! is_resource($stream)) {
            return false;
        }

        $context = hash_init('sha256');
        $bytes = hash_update_stream($context, $stream);
        fclose($stream);

        return $bytes !== false && hash_equals($document->checksum, hash_final($context));
    }
}
