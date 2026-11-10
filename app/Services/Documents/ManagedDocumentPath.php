<?php

namespace App\Services\Documents;

use App\Models\Document;

class ManagedDocumentPath
{
    public function forStoredName(string $storedName): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z0-9]{1,16}$/i', $storedName) !== 1) {
            return null;
        }

        return trim((string) config('documents.directory', 'documents'), '/').'/'.$storedName;
    }

    public function matches(Document $document): bool
    {
        $diskSettings = config("filesystems.disks.{$document->disk}", []);

        return $document->disk !== ''
            && $document->disk !== 'public'
            && is_array($diskSettings)
            && strtolower((string) ($diskSettings['visibility'] ?? 'private')) !== 'public'
            && $this->forStoredName($document->stored_name) === $document->path
            && ! str_contains($document->path, '..');
    }
}
