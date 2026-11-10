<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

class AdministrativeState
{
    public function __construct(
        private readonly SessionInvalidator $sessions,
        private readonly AdminAuditLogger $audit,
    ) {}

    public function changeUserStatus(User $actor, User $target, bool $active): void
    {
        $before = ['is_active' => $target->is_active];
        $target->forceFill(['is_active' => $active])->save();
        $this->sessions->invalidate([$target->id]);
        $this->audit->record($active ? 'user.activated' : 'user.deactivated', 'user', $target->id, $before, [
            'is_active' => $active,
        ]);
    }

    public function changeRoleStatus(User $actor, Role $role, bool $active): void
    {
        $before = ['is_active' => $role->is_active];
        $role->forceFill(['is_active' => $active])->save();
        $userIds = $role->users()->pluck('users.id')->all();
        $this->sessions->invalidate($userIds);
        $this->audit->record($active ? 'role.activated' : 'role.deactivated', 'role', $role->id, $before, [
            'is_active' => $active,
        ]);
    }
}
