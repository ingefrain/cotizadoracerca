<?php

namespace App\Imports;

use App\Models\User;
use App\Support\ImpersonationManager;

final class ImportAccess
{
    public function __construct(private readonly ImpersonationManager $session) {}

    public function actor(User $user): ?User
    {
        return $this->session->active() ? $this->session->actor() : $user;
    }

    public function allows(User $user, string ...$permissions): bool
    {
        $actor = $this->actor($user);
        foreach ($permissions as $permission) {
            if (! $user->hasPermissionTo($permission) || (! $actor?->is($user) && ! $actor?->hasPermissionTo($permission))) {
                return false;
            }
        }

        return true;
    }
}
