<?php

namespace App\Policies;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentOwnerAccess;

class DocumentPolicy
{
    public function __construct(private readonly DocumentOwnerAccess $ownerAccess) {}

    public function download(User $user, Document $document): bool
    {
        return $this->hasPermission($user, $document, 'documents.download') && $this->ownerAccess->allows($user, $document);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->hasPermission($user, $document, 'documents.update') && $this->ownerAccess->canManage($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->hasPermission($user, $document, 'documents.delete') && $this->ownerAccess->canManage($user, $document);
    }

    private function hasPermission(User $user, Document $document, string $permission): bool
    {
        if (in_array($document->documentable_type, [(new Catalog)->getMorphClass(), (new CatalogItem)->getMorphClass()], true)) {
            return CatalogPolicy::permits($user, $permission);
        }

        return $user->can($permission);
    }
}
