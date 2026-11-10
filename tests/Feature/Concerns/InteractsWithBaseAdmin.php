<?php

namespace Tests\Feature\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;

trait InteractsWithBaseAdmin
{
    protected function seedAccessCatalog(): void
    {
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    protected function adminUser(array $attributes = []): User
    {
        $this->seedAccessCatalog();

        return $this->userWithRole('administrator', $attributes);
    }

    protected function userWithRole(string $key, array $attributes = []): User
    {
        $role = Role::query()->where('key', $key)->firstOrFail();
        $user = User::factory()->create($attributes);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    /** @param list<string> $permissions */
    protected function userWithPermissions(array $permissions, array $attributes = []): User
    {
        $role = Role::query()->create([
            'name' => 'Prueba '.Str::random(6),
            'key' => 'test-'.Str::lower(Str::random(12)),
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id')->all());
        $user = User::factory()->create($attributes);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    protected function actingAsAdmin(?User $user = null): static
    {
        $user ??= $this->adminUser();
        $this->actingAs($user);
        $this->withSession(['auth_session_version' => $user->session_version]);

        return $this;
    }
}
