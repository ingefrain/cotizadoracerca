<?php

namespace App\Imports;

use App\Models\ImportRun;
use Generator;
use Illuminate\Support\Facades\Storage;

final class CsvSource
{
    public const DISK = 'import_sources';

    public static function maxBytes(): int
    {
        return max(1, (int) config('imports.max_size_kb')) * 1024;
    }

    public function open(ImportRun $run)
    {
        if ($run->disk !== self::DISK || $run->path !== $run->sourcePath()) {
            throw new ImportException('source_missing', 'El archivo fuente ya no está disponible.');
        }
        $source = Storage::disk(self::DISK)->readStream($run->sourcePath());
        if (! is_resource($source)) {
            throw new ImportException('source_missing', 'El archivo fuente ya no está disponible.');
        }

        // Parse precisely the bytes whose checksum was checked, even if the original changes.
        $snapshot = fopen('php://temp', 'w+b');
        try {
            $bytes = stream_copy_to_stream($source, $snapshot, self::maxBytes() + 1);
            rewind($snapshot);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $snapshot);
            if ($bytes === false || $bytes > self::maxBytes() || ! hash_equals($run->checksum_sha256, hash_final($hash))) {
                throw new ImportException('source_changed', 'El archivo cambió desde su carga. Carga otro archivo.');
            }
            rewind($snapshot);

            return $snapshot;
        } catch (\Throwable $exception) {
            fclose($snapshot);
            throw $exception;
        } finally {
            fclose($source);
        }
    }

    public function delimiter($stream, array $headers): string
    {
        $matches = [];
        foreach ([',', ';'] as $delimiter) {
            $this->rewind($stream);
            try {
                $header = $this->record($stream, $delimiter, 1);
            } catch (ImportException $exception) {
                if ($exception->reason === 'encoding') {
                    throw $exception;
                }

                continue;
            }
            if ($header === false || $header === [null]) {
                throw new ImportException('empty_file', 'El CSV está vacío.');
            }
            if (count($header) === count($headers) && array_diff($headers, $header) === []) {
                $matches[] = $delimiter;
            }
        }
        if (count($matches) !== 1) {
            throw new ImportException('header', 'Los encabezados deben coincidir con la plantilla, sin columnas adicionales ni repetidas. Usa coma o punto y coma.');
        }

        return $matches[0];
    }

    public function rows($stream, string $delimiter, int $max): Generator
    {
        $this->rewind($stream);
        $header = $this->record($stream, $delimiter, 1);
        $count = 0;
        for ($row = 2; ($cells = $this->record($stream, $delimiter, $row)) !== false; $row++) {
            if ($cells === [null]) {
                continue;
            }
            if (++$count > $max) {
                throw new ImportException('max_rows', 'El CSV excede el máximo de filas permitido.', $row);
            }

            yield $row => count($cells) === count($header) ? array_combine($header, $cells) : null;
        }
        if ($count === 0) {
            throw new ImportException('empty_rows', 'El CSV debe contener al menos una fila de datos.');
        }
    }

    private function rewind($stream): void
    {
        rewind($stream);
        if (fread($stream, 3) !== "\xEF\xBB\xBF") {
            rewind($stream);
        }
    }

    private function record($stream, string $delimiter, int $row): array|false
    {
        $start = ftell($stream);
        $cells = fgetcsv($stream, separator: $delimiter, escape: '');
        if ($cells === false) {
            return false;
        }
        $end = ftell($stream);
        fseek($stream, $start);
        $raw = fread($stream, $end - $start);
        if (! is_string($raw) || str_contains($raw, "\0") || ! mb_check_encoding($raw, 'UTF-8')) {
            throw new ImportException('encoding', 'El CSV debe usar UTF-8 válido y sin bytes nulos.', $row);
        }

        $separator = preg_quote($delimiter, '~');
        $field = '(?:"(?:[^"]++|"")*+"|[^"'.$separator.'\r\n]*+)';
        if (! preg_match('~\A'.$field.'(?:'.$separator.$field.')*(?:\r\n|\n|\r)?\z~', $raw)) {
            throw new ImportException('csv_syntax', 'La fila contiene comillas o separadores inválidos.', $row);
        }

        return $cells;
    }
}
