<?php

namespace App\Imports;

use App\Models\CatalogItem;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\AdminAuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ImportService
{
    public function __construct(private readonly CsvSource $csv, private readonly AdminAuditLogger $audit) {}

    public function upload(UploadedFile $file, string $type, User $user, User $actor): ImportRun
    {
        $uuid = (string) Str::uuid();
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $file->getClientOriginalName()) ?: 'archivo.csv';
        $run = new ImportRun([
            'type' => $type,
            'original_name' => Str::limit($name, 180, ''),
            'disk' => CsvSource::DISK,
            'checksum_sha256' => hash_file('sha256', $file->getPathname()),
            'created_by' => $actor->id,
            'effective_user_id' => $user->id,
            'expires_at' => now()->addHours(max(1, (int) config('imports.retention_hours'))),
            'status' => 'uploaded',
        ]);
        $run->forceFill(['uuid' => $uuid]);
        $run->path = $run->sourcePath();
        $stream = fopen($file->getPathname(), 'rb');
        try {
            DB::transaction(function () use ($run, $user, $stream): void {
                $run->save();
                try {
                    if (! Storage::disk(CsvSource::DISK)->put($run->sourcePath(), $stream)) {
                        throw new ImportException('storage', 'No se pudo guardar el archivo temporal.');
                    }
                    $this->audit($run, 'uploaded');
                    $run->update(['status' => 'validating']);
                    DB::transaction(fn () => $this->validate($run, $user));
                    $this->audit($run, 'validated');
                } catch (ImportException $exception) {
                    $this->fail($run, $exception);
                }
            });
        } finally {
            fclose($stream);
        }

        return $run;
    }

    public function apply(ImportRun $run, User $user): ImportRun
    {
        try {
            $run = DB::transaction(function () use ($run, $user) {
                $locked = ImportRun::query()->lockForUpdate()->findOrFail($run->id);
                abort_unless($locked->status === 'ready' && $locked->expires_at->isFuture(), 409);
                $locked->update(['status' => 'applying']);
                $records = fopen('php://temp', 'w+b');
                try {
                    $this->validate($locked, $user, $records);
                    if ($locked->status === 'ready') {
                        rewind($records);
                        while (($line = fgets($records)) !== false) {
                            $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                            $model = ImportDefinition::TYPES[$locked->type]['model'];
                            $entity = new $model($data);
                            if ($entity instanceof CatalogItem) {
                                $entity->catalog()->associate($data['catalog_id']);
                            }
                            $entity->save();
                        }
                        $locked->update(['status' => 'completed', 'applied_rows' => $locked->valid_rows, 'applied_at' => now()]);
                    }
                    $this->audit($locked, $locked->status === 'completed' ? 'applied' : 'failed');

                    return $locked;
                } finally {
                    fclose($records);
                }
            });
        } catch (ImportException|QueryException $exception) {
            return DB::transaction(function () use ($run, $exception) {
                $locked = ImportRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($locked->status === 'ready') {
                    $this->fail($locked, $exception instanceof ImportException ? $exception
                        : new ImportException('database_conflict', 'No se aplicaron cambios. Comprueba los datos y carga nuevamente el archivo.'));
                }

                return $locked;
            });
        }
        if ($run->status === 'completed') {
            $this->deleteSource($run);
        }

        return $run;
    }

    public function preview(ImportRun $run): array
    {
        if (! in_array($run->status, ['ready', 'invalid'], true) || ! $run->path || ! $run->delimiter || $run->expires_at->isPast()) {
            return [];
        }
        $stream = null;
        try {
            $stream = $this->csv->open($run);
            $rows = [];
            foreach ($this->csv->rows($stream, $run->delimiter, $this->maxRows()) as $number => $row) {
                if ($row !== null) {
                    $rows[$number] = array_map(fn ($value) => Str::limit((string) $value, 180), $row);
                }
                if (count($rows) >= 25) {
                    break;
                }
            }

            return $rows;
        } catch (ImportException) {
            return [];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function delete(ImportRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = ImportRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless($locked->canBeDeleted(), 409);

            $this->audit->record('import.deleted', 'import_run', $locked->uuid,
                before: $locked->only(['type', 'status']), actorId: $locked->created_by);
            $locked->delete();

            if ($locked->path !== null && ($locked->disk !== CsvSource::DISK
                || $locked->path !== $locked->sourcePath()
                || ! Storage::disk(CsvSource::DISK)->delete($locked->path))) {
                throw new ImportException('storage', 'No fue posible eliminar el archivo temporal. Intenta de nuevo.');
            }
        });
    }

    public function prune(bool $dryRun): int
    {
        $expired = ImportRun::query()->where('expires_at', '<=', now())
            ->where(fn ($query) => $query->whereNotNull('path')->orWhereHas('errors')->orWhereNotIn('status', ['completed', 'expired']));
        if ($dryRun) {
            return $expired->count();
        }
        $count = 0;
        foreach ($expired->lazyById(100) as $run) {
            DB::transaction(function () use ($run, &$count): void {
                $locked = ImportRun::query()->lockForUpdate()->find($run->id);
                if ($locked === null) {
                    return;
                }
                $this->deleteSource($locked);
                $locked->errors()->delete();
                if (! in_array($locked->status, ['completed', 'expired'], true)) {
                    $locked->update(['status' => 'expired']);
                    $this->audit($locked, 'expired');
                }
                $this->audit($locked, 'pruned');
                $count++;
            });
        }

        return $count;
    }

    private function validate(ImportRun $run, User $user, $records = null): void
    {
        $stream = $this->csv->open($run);
        $definition = new ImportDefinition($run->type, $user, $records !== null);
        $deadline = hrtime(true) + max(1, (int) config('imports.max_seconds')) * 1_000_000_000;
        $total = $valid = 0;
        $run->errors()->delete();
        try {
            $delimiter = $this->csv->delimiter($stream, ImportDefinition::TYPES[$run->type]['headers']);
            foreach ($this->csv->rows($stream, $delimiter, $this->maxRows()) as $number => $row) {
                if (hrtime(true) > $deadline) {
                    throw new ImportException('time_limit', 'Se agotó el tiempo de validación. Divide el archivo.');
                }
                $total++;
                [$data, $errors] = $row === null
                    ? [[], ['' => ['column_count', 'La cantidad de columnas no coincide con los encabezados.']]]
                    : $definition->validate($row);
                if ($errors !== []) {
                    foreach ($errors as $field => [$code, $message]) {
                        $run->errors()->create(['row_number' => $number, 'field' => $field ?: null, 'error_code' => $code, 'message' => $message]);
                    }

                    continue;
                }
                $valid++;
                if ($records !== null) {
                    $line = json_encode($data, JSON_THROW_ON_ERROR)."\n";
                    if (fwrite($records, $line) !== strlen($line)) {
                        throw new ImportException('storage', 'No se pudo preparar la importación temporal.');
                    }
                }
            }
            $run->update([
                'delimiter' => $delimiter,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'invalid_rows' => $total - $valid,
                'status' => $total === $valid ? 'ready' : 'invalid',
                'validated_at' => now(),
            ]);
        } finally {
            fclose($stream);
        }
    }

    private function fail(ImportRun $run, ImportException $exception): void
    {
        $run->errors()->create(['row_number' => $exception->row, 'error_code' => $exception->reason, 'message' => $exception->getMessage()]);
        $run->update(['status' => 'failed']);
        $this->audit($run, 'failed');
    }

    private function maxRows(): int
    {
        return max(1, (int) config('imports.max_rows'));
    }

    private function deleteSource(ImportRun $run): void
    {
        if ($run->disk === CsvSource::DISK && $run->path === $run->sourcePath()
            && Storage::disk(CsvSource::DISK)->delete($run->path)) {
            $run->update(['path' => null]);
        }
    }

    private function audit(ImportRun $run, string $action): void
    {
        $this->audit->record('import.'.$action, 'import_run', $run->uuid, after: $run->only([
            'type', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'applied_rows',
        ]), actorId: in_array($action, ['pruned', 'expired'], true) ? null : $run->created_by);
    }
}
