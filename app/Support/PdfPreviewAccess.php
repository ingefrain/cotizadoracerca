<?php

namespace App\Support;

use App\Models\User;

final class PdfPreviewAccess
{
    public function __construct(private readonly ImpersonationManager $session) {}

    public function allows(?User $user): bool
    {
        $actor = $this->session->active() ? $this->session->actor() : $user;

        return $user?->hasPermissionTo('pdf.preview') && $actor?->hasPermissionTo('pdf.preview');
    }
}
