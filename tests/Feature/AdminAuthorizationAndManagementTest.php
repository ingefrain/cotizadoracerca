<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class AdminAuthorizationAndManagementTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_users_list_requires_permission_and_supports_search_state_role_and_pagination(): void
    {
        $admin = $this->adminUser();
        $operator = Role::query()->where('key', 'operator')->firstOrFail();
        User::factory()->create(['name' => 'Lucía Activa', 'email' => 'lucia@example.test']);
        User::factory()->create(['name' => 'Lucía Inactiva', 'email' => 'lucia-off@example.test', 'is_active' => false]);
        $assigned = User::factory()->create(['name' => 'Lucía Operadora', 'email' => 'lucia-role@example.test']);
        $assigned->roles()->attach($operator);
        User::factory(18)->create();

        $this->actingAs($this->userWithPermissions(['dashboard.view']))
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAsAdmin($admin)->get(route('admin.users.index', ['q' => 'Lucía', 'status' => 'inactive']))
            ->assertOk()->assertSee('Lucía Inactiva')->assertDontSee('Lucía Activa');
        $this->actingAsAdmin($admin)->get(route('admin.users.index', ['q' => 'Lucía', 'role' => $operator->id]))
            ->assertOk()->assertSee('Lucía Operadora')->assertDontSee('Lucía Activa');
        $this->actingAsAdmin($admin)->get(route('admin.users.index'))
            ->assertOk()->assertViewHas('users', fn ($users) => $users->perPage() === 15 && $users->total() >= 20);
    }

    public function test_administrative_pages_render_with_generic_branding(): void
    {
        $admin = $this->adminUser();
        $role = Role::query()->where('key', 'administrator')->firstOrFail();

        $this->actingAsAdmin($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Cotizador Acerca');
        $this->actingAsAdmin($admin)->get(route('admin.users.create'))
            ->assertOk()->assertSee('Crear usuario')
            ->assertSee('data-password-policy', false)
            ->assertSee('data-password-toggle', false)
            ->assertSee('data-password-limit', false)
            ->assertSee('name="role_ids[]"', false)
            ->assertSee('data-role-selector', false)
            ->assertSee('Selecciona uno o más roles para este usuario.');
        $target = User::factory()->create();
        $editResponse = $this->actingAsAdmin($admin)->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('data-password-optional="true"', false)
            ->assertSee('data-password-checklist', false)
            ->assertSee('data-password-toggle', false);
        $this->assertMatchesRegularExpression('/<ul[^>]*data-password-checklist[^>]*\shidden\b/', $editResponse->getContent());
        $this->actingAsAdmin($admin)->get(route('admin.roles.create'))->assertOk()->assertSee('Crear rol');
        $this->actingAsAdmin($admin)->get(route('admin.roles.edit', $role))->assertOk()->assertSee('Permisos');
        $this->actingAsAdmin($admin)->get(route('admin.permissions.index'))->assertOk()->assertSee('users.disable');
        $this->actingAsAdmin($admin)->get(route('admin.profile.edit'))->assertOk()->assertSee('Mi perfil');
    }

    public function test_admin_can_create_update_searchable_user_and_assign_a_role(): void
    {
        $admin = $this->adminUser();
        $operator = Role::query()->where('key', 'operator')->firstOrFail();

        $this->actingAsAdmin($admin)->post(route('admin.users.store'), [
            'name' => 'Ana Operadora',
            'email' => 'ANA@example.test',
            'password' => 'Clave-segura-1234!',
            'password_confirmation' => 'Clave-segura-1234!',
            'role_assignment_present' => '1',
            'role_ids' => [$operator->id],
        ])->assertRedirect(route('admin.users.edit', User::query()->where('email', 'ana@example.test')->value('id')))
            ->assertSessionHas('status', 'Usuario creado correctamente.');

        $user = User::query()->where('email', 'ana@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Clave-segura-1234!', $user->password));
        $this->assertSame([$operator->id], $user->roles()->pluck('roles.id')->all());
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'user.created', 'entity_id' => (string) $user->id]);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'user.roles_assigned', 'entity_id' => (string) $user->id]);

        $this->actingAsAdmin($admin)->put(route('admin.users.update', $user), [
            'name' => 'Ana Editada',
            'email' => 'ana@example.test',
            'role_assignment_present' => '1',
            'role_ids' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Ana Editada']);
        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_admin_can_create_and_edit_a_user_with_multiple_roles(): void
    {
        $admin = $this->adminUser();
        $operator = Role::query()->where('key', 'operator')->firstOrFail();
        $support = Role::query()->create(['name' => 'Soporte', 'key' => 'support', 'is_active' => true]);

        $this->actingAsAdmin($admin)->post(route('admin.users.store'), [
            'name' => 'Multirrol',
            'email' => 'multirrol@example.test',
            'password' => 'Clave-segura-1234!',
            'password_confirmation' => 'Clave-segura-1234!',
            'role_assignment_present' => '1',
            'role_ids' => [$operator->id, $support->id],
        ])->assertRedirect();

        $user = User::query()->where('email', 'multirrol@example.test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$operator->id, $support->id], $user->roles()->pluck('roles.id')->all());

        $this->actingAsAdmin($admin)->put(route('admin.users.update', $user), [
            'name' => 'Multirrol actualizado',
            'email' => $user->email,
            'role_assignment_present' => '1',
            'role_ids' => [$support->id],
        ])->assertRedirect(route('admin.users.edit', $user));

        $this->assertSame([$support->id], $user->fresh()->roles()->pluck('roles.id')->all());
    }

    public function test_user_role_assignment_rejects_unknown_role_ids(): void
    {
        $admin = $this->adminUser();

        $this->actingAsAdmin($admin)->post(route('admin.users.store'), [
            'name' => 'Invalid role',
            'email' => 'invalid-role@example.test',
            'password' => 'Clave-segura-1234!',
            'password_confirmation' => 'Clave-segura-1234!',
            'role_assignment_present' => '1',
            'role_ids' => [999999],
        ])->assertSessionHasErrors('role_ids.0');

        $this->assertDatabaseMissing('users', ['email' => 'invalid-role@example.test']);
    }

    public function test_user_without_role_assignment_permission_cannot_manipulate_role_ids(): void
    {
        $this->seedAccessCatalog();
        $actor = $this->userWithPermissions(['users.update']);
        $target = User::factory()->create();

        $this->actingAs($actor)->withSession(['auth_session_version' => $actor->session_version])
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role_assignment_present' => '1',
                'role_ids' => [],
            ])->assertForbidden();
    }

    public function test_last_administrator_cannot_be_deactivated_or_lose_a_critical_role(): void
    {
        $admin = $this->adminUser();
        $operator = Role::query()->where('key', 'operator')->firstOrFail();

        $this->actingAsAdmin($admin)->patch(route('admin.users.status', $admin))
            ->assertSessionHasErrors('administrative_access');
        $this->assertTrue($admin->fresh()->is_active);

        $this->actingAsAdmin($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_assignment_present' => '1',
            'role_ids' => [$operator->id],
        ])->assertSessionHasErrors('administrative_access');
        $this->assertSame(['administrator'], $admin->fresh()->roles->pluck('key')->all());
    }

    public function test_last_administrator_cannot_remove_a_critical_permission_from_its_role(): void
    {
        $admin = $this->adminUser();
        $role = Role::query()->where('key', 'administrator')->firstOrFail();
        $permissionIds = Permission::query()->whereIn('key', array_keys(BasePermissions::DEFINITIONS))
            ->where('key', '!=', 'permissions.assign')->pluck('id')->all();
        $version = $admin->session_version;

        $this->actingAsAdmin($admin)->put(route('admin.roles.update', $role), [
            'name' => $role->name,
            'description' => $role->description,
            'permission_assignment_present' => '1',
            'permission_ids' => $permissionIds,
        ])->assertSessionHasErrors('administrative_access');

        $this->assertDatabaseHas('permission_role', [
            'role_id' => $role->id,
            'permission_id' => Permission::query()->where('key', 'permissions.assign')->value('id'),
        ]);
        $this->assertSame($version, $admin->fresh()->session_version);
    }

    public function test_last_administrator_cannot_deactivate_its_only_administrative_role(): void
    {
        $admin = $this->adminUser();
        $role = Role::query()->where('key', 'administrator')->firstOrFail();

        $this->actingAsAdmin($admin)->patch(route('admin.roles.status', $role))
            ->assertSessionHasErrors('administrative_access');

        $this->assertTrue($role->fresh()->is_active);
        $this->assertTrue($admin->fresh()->can('users.update'));
    }

    public function test_admin_can_create_a_role_with_only_known_capabilities(): void
    {
        $admin = $this->adminUser();
        $permission = Permission::query()->where('key', 'users.view')->firstOrFail();

        $this->actingAsAdmin($admin)->post(route('admin.roles.store'), [
            'name' => 'Consulta',
            'key' => 'read-only',
            'description' => 'Puede consultar cuentas.',
            'permission_assignment_present' => '1',
            'permission_ids' => [$permission->id],
        ])->assertRedirect();

        $role = Role::query()->where('key', 'read-only')->firstOrFail();
        $this->assertSame(['users.view'], $role->permissions()->pluck('key')->all());
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'role.created', 'entity_id' => (string) $role->id]);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'role.permissions_changed', 'entity_id' => (string) $role->id]);
    }

    public function test_deactivation_with_another_administrator_rotates_credentials_and_deletes_sessions(): void
    {
        $actor = $this->adminUser();
        $target = $this->userWithRole('administrator');
        $oldToken = $target->remember_token;
        $oldVersion = $target->session_version;
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => time(),
        ]);

        $this->actingAsAdmin($actor)->patch(route('admin.users.status', $target))->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertNotSame($oldToken, $target->remember_token);
        $this->assertSame($oldVersion + 1, $target->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'user.deactivated', 'entity_id' => (string) $target->id]);
    }

    public function test_account_with_stale_session_version_is_logged_out(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->withSession(['auth_session_version' => $admin->session_version - 1])
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_permission_assignment_cannot_exceed_actor_capabilities(): void
    {
        $this->seedAccessCatalog();
        $actor = $this->userWithPermissions(['roles.create', 'permissions.assign', 'users.create']);
        $admin = $this->userWithRole('administrator');
        $forbidden = Permission::query()->where('key', 'users.disable')->firstOrFail();

        $this->actingAs($actor)->withSession(['auth_session_version' => $actor->session_version])
            ->post(route('admin.roles.store'), [
                'name' => 'Rol indebido',
                'key' => 'unauthorized-role',
                'permission_assignment_present' => '1',
                'permission_ids' => [$forbidden->id],
            ])->assertSessionHasErrors('permission_ids');

        $this->assertDatabaseMissing('roles', ['key' => 'unauthorized-role']);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_role_permissions_and_role_assignment_are_capability_authorized(): void
    {
        $this->seedAccessCatalog();
        $actor = $this->userWithPermissions(['roles.view', 'roles.update']);
        $targetRole = Role::query()->where('key', 'operator')->firstOrFail();

        $this->actingAs($actor)->withSession(['auth_session_version' => $actor->session_version])
            ->get(route('admin.permissions.index'))->assertForbidden();
        $this->actingAs($actor)->withSession(['auth_session_version' => $actor->session_version])
            ->put(route('admin.roles.update', $targetRole), [
                'name' => 'Operador',
                'permission_assignment_present' => '1',
                'permission_ids' => [],
            ])->assertForbidden();
    }

    public function test_permission_changes_invalidate_assigned_users_and_are_audited_safely(): void
    {
        $admin = $this->adminUser();
        $operator = Role::query()->where('key', 'operator')->firstOrFail();
        $viewPermission = Permission::query()->where('key', 'users.view')->firstOrFail();
        $operator->permissions()->sync([$viewPermission->id]);
        $member = $this->userWithRole('operator');
        $version = $member->session_version;
        DB::table('sessions')->insert([
            'id' => 'operator-session',
            'user_id' => $member->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => time(),
        ]);

        $this->actingAsAdmin($admin)->put(route('admin.roles.update', $operator), [
            'name' => 'Operador',
            'description' => 'Capacidades limitadas',
            'permission_assignment_present' => '1',
            'permission_ids' => [],
        ])->assertRedirect();

        $this->assertSame($version + 1, $member->fresh()->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'operator-session']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'role.permissions_changed', 'entity_id' => (string) $operator->id]);
    }

    public function test_audit_page_is_protected_and_events_do_not_contain_password_material(): void
    {
        $this->seedAccessCatalog();
        $actor = $this->userWithPermissions(['dashboard.view']);
        $this->actingAs($actor)->withSession(['auth_session_version' => $actor->session_version])
            ->get(route('admin.audit.index'))->assertForbidden();

        $admin = $this->userWithRole('administrator');
        $this->actingAsAdmin($admin)->post(route('admin.users.store'), [
            'name' => 'Auditada',
            'email' => 'auditada@example.test',
            'password' => 'Clave-segura-1234!',
            'password_confirmation' => 'Clave-segura-1234!',
        ])->assertRedirect();

        $event = AdminAuditEvent::query()->where('action', 'user.created')->latest('id')->firstOrFail();
        $serialized = json_encode([$event->before, $event->after, $event->context]);
        $this->assertStringNotContainsString('Clave-segura-1234!', $serialized);
        $this->assertStringNotContainsString('password', strtolower($serialized));
        $this->actingAsAdmin($admin)->get(route('admin.audit.index'))->assertOk()->assertSee('Auditada');
    }

    public function test_profile_does_not_allow_role_or_account_state_changes(): void
    {
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin)->put(route('admin.profile.update'), [
            'name' => 'Nombre nuevo',
            'is_active' => false,
            'role_ids' => [],
        ])->assertRedirect();

        $this->assertSame('Nombre nuevo', $admin->fresh()->name);
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(['administrator'], $admin->fresh()->roles->pluck('key')->all());
    }

    public function test_profile_password_change_preserves_current_session_and_invalidates_others(): void
    {
        $admin = $this->adminUser(['password' => 'Clave-anterior-segura-123!']);
        DB::table('sessions')->insert([
            'id' => 'other-profile-session',
            'user_id' => $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => time(),
        ]);

        $this->actingAsAdmin($admin)->put(route('admin.profile.password'), [
            'password' => 'Clave-nueva-segura-456!',
            'password_confirmation' => 'Clave-nueva-segura-456!',
        ])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertTrue(Hash::check('Clave-nueva-segura-456!', $admin->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-profile-session']);
        $this->assertSame($admin->fresh()->session_version, session('auth_session_version'));
    }
}
