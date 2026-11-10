<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdministrativeAccess
{
    public function transaction(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            DB::table('administrative_guards')->where('id', 1)->lockForUpdate()->firstOrFail();
            $result = $callback();
            $this->assertAdministratorRemains();

            return $result;
        });
    }

    public function assertAdministratorRemains(): void
    {
        $required = BasePermissions::ADMINISTRATIVE_CORE;
        $users = User::query()
            ->where('is_active', true)
            ->with(['roles' => fn ($query) => $query->where('roles.is_active', true), 'roles.permissions'])
            ->get();

        foreach ($users as $user) {
            $keys = $user->roles->flatMap(fn (Role $role) => $role->permissions->pluck('key'))->unique();
            if (collect($required)->every(fn (string $key) => $keys->contains($key))) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'administrative_access' => 'La operación dejaría el sistema sin una cuenta activa con control administrativo completo.',
        ]);
    }

    /** @param list<int> $roleIds */
    public function syncUserRoles(User $actor, User $target, array $roleIds): bool
    {
        $actor = app(ImpersonationManager::class)->actor() ?? $actor;
        $roles = Role::query()->whereIn('id', $roleIds)->where('is_active', true)->with('permissions')->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw ValidationException::withMessages(['role_ids' => 'Selecciona roles activos válidos.']);
        }

        $actorPermissions = $actor->permissionKeys();
        $requestedPermissions = $roles->flatMap(fn (Role $role) => $role->permissions->pluck('key'))->unique();
        if ($requestedPermissions->diff($actorPermissions)->isNotEmpty()) {
            throw ValidationException::withMessages(['role_ids' => 'No puedes asignar capacidades que no tienes.']);
        }

        $before = $target->roles()->pluck('roles.id')->sort()->values()->all();
        $after = $roles->pluck('id')->sort()->values()->all();
        if ($before !== $after) {
            $target->roles()->sync($after);
        }

        return $before !== $after;
    }

    /** @param list<int> $permissionIds */
    public function syncRolePermissions(User $actor, Role $role, array $permissionIds): bool
    {
        $actor = app(ImpersonationManager::class)->actor() ?? $actor;
        $permissions = Permission::query()->whereIn('id', $permissionIds)->get();
        if ($permissions->count() !== count(array_unique($permissionIds))) {
            throw ValidationException::withMessages(['permission_ids' => 'Selecciona permisos válidos.']);
        }

        if ($permissions->pluck('key')->diff($actor->permissionKeys())->isNotEmpty()) {
            throw ValidationException::withMessages(['permission_ids' => 'No puedes asignar capacidades que no tienes.']);
        }

        if ($role->key !== 'administrator' && $permissions->contains('key', 'users.impersonate')) {
            throw ValidationException::withMessages(['permission_ids' => 'La capacidad de impersonación solo puede pertenecer al rol administrador.']);
        }

        $before = $role->permissions()->pluck('permissions.id')->sort()->values()->all();
        $after = $permissions->pluck('id')->sort()->values()->all();
        if ($before !== $after) {
            $role->permissions()->sync($after);
        }

        return $before !== $after;
    }
}
