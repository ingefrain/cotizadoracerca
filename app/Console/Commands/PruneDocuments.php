<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Documents\ManagedDocumentPath;
use App\Support\AdminAuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneDocuments extends Command
{
    protected $signature = 'documents:prune {--dry-run : Report expired items without deleting them} {--purge : Permanently delete expired document rows and files} {--orphans : Also report old orphan files} {--purge-orphans : Permanently delete old orphan files} {--limit=100 : Maximum documents and objects to inspect}';

    protected $description = 'Reporta o purga documentos eliminados tras el periodo de retención configurado.';

    public function handle(AdminAuditLogger $audit, ManagedDocumentPath $managedPath): int
    {
        $retention = (int) config('documents.retention_days', 0);
        $purgeDocuments = (bool) $this->option('purge');
        $purgeOrphans = (bool) $this->option('purge-orphans');
        $includeOrphans = (bool) $this->option('orphans') || $purgeOrphans;
        if ($this->option('dry-run') && ($purgeDocuments || $purgeOrphans)) {
            $this->error('No combines --dry-run con --purge ni --purge-orphans.');

            return self::INVALID;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $expired = $retention > 0
            ? Document::onlyTrashed()->where('deleted_at', '<=', now()->subDays($retention))->orderBy('deleted_at')->limit($limit)->get()
            : collect();
        if ($retention <= 0 && $purgeDocuments) {
            $this->warn('La purga de documentos está deshabilitada: retention_days debe ser mayor que cero.');
        }

        $deletedDocuments = 0;
        $failures = 0;
        if ($purgeDocuments) {
            foreach ($expired as $document) {
                try {
                    if (! $managedPath->matches($document)) {
                        $failures++;

                        continue;
                    }
                    $disk = Storage::disk($document->disk);
                    DB::transaction(function () use ($document, $audit): void {
                        $audit->record('document.purged', 'document', $document->uuid, null, [
                            'category' => $document->category,
                            'retention_days' => (int) config('documents.retention_days', 0),
                        ]);
                        $document->forceDelete();
                    });
                    if ($disk->exists($document->path) && ! $disk->delete($document->path)) {
                        $failures++;

                        continue;
                    }
                    $deletedDocuments++;
                } catch (Throwable) {
                    $failures++;
                }
            }
            $this->line('Documentos purgados: '.$deletedDocuments);
        } else {
            $this->info('Modo de revisión; no se eliminó ningún archivo.');
            $this->line('Documentos vencidos dentro del límite: '.$expired->count());
            if ($retention > 0) {
                $this->line('Retención configurada: '.$retention.' días.');
                $this->line('Para purgar documentos, revisa el resultado y ejecuta nuevamente con --purge.');
            }
        }

        if ($includeOrphans) {
            [$orphanCandidates, $orphanDeleted, $orphanFailures] = $this->processOrphans($limit, $purgeOrphans, $audit);
            $failures += $orphanFailures;
            $this->line('Archivos huérfanos vencidos dentro del límite: '.$orphanCandidates);
            if ($purgeOrphans) {
                $this->line('Archivos huérfanos purgados: '.$orphanDeleted);
            } else {
                $this->line('Para retirar huérfanos vencidos, revisa el resultado y ejecuta nuevamente con --purge-orphans.');
            }
        }

        $this->line('Fallos: '.$failures);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{int, int, int} */
    private function processOrphans(int $limit, bool $purge, AdminAuditLogger $audit): array
    {
        $diskName = (string) config('documents.disk', 'local');
        $diskSettings = config("filesystems.disks.{$diskName}", []);
        if ($diskName === 'public' || ! is_array($diskSettings) || strtolower((string) ($diskSettings['visibility'] ?? 'private')) === 'public') {
            $this->error('La purga de archivos huérfanos requiere un disk privado.');

            return [0, 0, 1];
        }
        $disk = Storage::disk($diskName);
        $directory = trim((string) config('documents.directory', 'documents'), '/');
        $retention = (int) config('documents.orphan_retention_days', 30);
        if ($retention <= 0) {
            $this->warn('La revisión de huérfanos está deshabilitada: orphan_retention_days debe ser mayor que cero.');

            return [0, 0, 0];
        }

        $cutoff = now()->subDays($retention)->getTimestamp();
        $candidates = 0;
        $deleted = 0;
        $failures = 0;
        $scanned = 0;
        $pathForStoredName = app(ManagedDocumentPath::class);
        try {
            foreach ($disk->getDriver()->listContents($directory, true) as $item) {
                if (! $item->isFile()) {
                    continue;
                }
                if ($scanned >= $limit) {
                    break;
                }
                $scanned++;
                $path = $item->path();
                $name = basename($path);
                if ($pathForStoredName->forStoredName($name) !== $path
                    || Document::withTrashed()->where('disk', $diskName)->where('path', $path)->exists()) {
                    continue;
                }

                try {
                    if ($disk->lastModified($path) > $cutoff) {
                        continue;
                    }
                } catch (Throwable) {
                    $failures++;

                    continue;
                }

                $candidates++;
                if (! $purge) {
                    continue;
                }
                try {
                    if (! $disk->delete($path)) {
                        $failures++;

                        continue;
                    }
                    $audit->record('document.orphan_file_purged', 'document_file', null, null, [
                        'retention_days' => $retention,
                    ]);
                    $deleted++;
                } catch (Throwable) {
                    $failures++;
                }
            }
        } catch (Throwable) {
            $this->error('No se pudo completar el recorrido acotado de archivos huérfanos.');
            $failures++;
        }

        return [$candidates, $deleted, $failures];
    }
}
