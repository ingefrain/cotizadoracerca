<?php

namespace App\Support;

use App\Models\User;

/** Shared internal documents; every operation needs both identities' permissions. */
final class FormattedDocumentAccess
{
    public function __construct(private readonly ImpersonationManager $session) {}

    public function allows(?User $user, string $operation = 'view'): bool
    {
        if (! in_array($operation, ['view', 'create', 'update'], true)) {
            return false;
        }
        $actor = $this->session->active() ? $this->session->actor() : $user;
        foreach (array_unique(['pdf-documents.view', 'pdf-documents.'.$operation]) as $permission) {
            if (! $user?->hasPermissionTo($permission) || ! $actor?->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }
}
