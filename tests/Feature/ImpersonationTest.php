<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminLoginLimiter;
use App\Support\ImpersonationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_administrator_can_impersonate_and_return_with_audited_dual_identity(): void
    {
        $actor = $this->adminUser(['last_login_at' => now()->subDay(), 'last_login_ip' => '192.0.2.10']);
        $target = User::factory()->create();
        $target->forceFill(['last_activity_at' => now()->subDays(2)])->save();
        $targetLoginAt = $target->last_login_at;
        $targetLoginIp = $target->last_login_ip;
        $targetActivityAt = $target->last_activity_at;
        $targetRememberToken = $target->remember_token;
        $attemptsBefore = DB::table('login_attempts')->count();
        $limiter = app(AdminLoginLimiter::class);
        foreach ([$actor, $target] as $account) {
            for ($failure = 0; $failure < 4; $failure++) {
                $limiter->recordFailure($account->email, '127.0.0.1');
            }
        }

        $this->actingAsAdmin($actor)->get(route('admin.users.index'))->assertOk()->assertSee('Ver como usuario');

        $this->actingAsAdmin($actor)
            ->post(route('admin.users.impersonate', $target))
            ->assertRedirect(route('admin.profile.edit'));

        $this->assertAuthenticatedAs($target);
        $this->assertSame($actor->id, app(ImpersonationManager::class)->actor()?->id);
        $this->assertSame($target->id, app(ImpersonationManager::class)->effectiveUser()?->id);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $actor->id,
            'action' => 'impersonation.started',
            'entity_id' => (string) $target->id,
        ]);

        $started = AdminAuditEvent::query()->where('action', 'impersonation.started')->firstOrFail();
        $this->assertSame($actor->id, $started->context['impersonation']['actor_id']);
        $this->assertSame($target->id, $started->context['impersonation']['target_id']);
        $this->assertSame($targetLoginAt, $target->fresh()->last_login_at);
        $this->assertSame($targetLoginIp, $target->fresh()->last_login_ip);
        $this->assertSame($attemptsBefore, DB::table('login_attempts')->count());
        $this->assertSame($targetActivityAt->toDateTimeString(), $target->fresh()->last_activity_at->toDateTimeString());
        $this->assertSame($targetRememberToken, $target->fresh()->remember_token);

        $this->get(route('admin.profile.edit'))->assertOk()
            ->assertSee('Estás actuando como '.$target->name)
            ->assertSee('Sesión iniciada por '.$actor->name)
            ->assertSee('Volver a mi sesión')
            ->assertDontSee('Configuración');

        $this->post(route('admin.impersonation.stop'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($actor);
        $this->assertSame($attemptsBefore, DB::table('login_attempts')->count());
        $this->assertSame($targetLoginAt, $target->fresh()->last_login_at);
        $this->assertSame($actor->last_login_at->toDateTimeString(), $actor->fresh()->last_login_at->toDateTimeString());
        foreach ([$actor, $target] as $account) {
            $this->assertSame(0, $limiter->retryAfter($account->email, '127.0.0.1'));
            $limiter->recordFailure($account->email, '127.0.0.1');
            $this->assertGreaterThan(0, $limiter->retryAfter($account->email, '127.0.0.1'));
        }
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $actor->id,
            'action' => 'impersonation.ended',
            'entity_id' => (string) $target->id,
        ]);
    }

    public function test_only_administrators_can_impersonate_and_nested_impersonation_is_rejected(): void
    {
        $this->seedAccessCatalog();
        $operator = $this->userWithRole('operator');
        $normal = User::factory()->create(['is_active' => true]);
        $target = User::factory()->create();

        $this->actingAs($operator)->withSession(['auth_session_version' => $operator->session_version])
            ->post(route('admin.users.impersonate', $target))->assertForbidden();
        $this->actingAs($normal)->withSession(['auth_session_version' => $normal->session_version])
            ->post(route('admin.users.impersonate', $target))->assertForbidden();

        $actor = $this->adminUser();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));
        $another = User::factory()->create();
        $this->post(route('admin.users.impersonate', $another))->assertForbidden();

        $this->assertDatabaseHas('permission_role', [
            'permission_id' => Permission::query()->where('key', 'users.impersonate')->value('id'),
            'role_id' => Role::query()->where('key', 'administrator')->value('id'),
        ]);
        $this->assertDatabaseMissing('permission_role', [
            'permission_id' => Permission::query()->where('key', 'users.impersonate')->value('id'),
            'role_id' => Role::query()->where('key', 'operator')->value('id'),
        ]);
    }

    public function test_inactive_target_can_be_impersonated_but_remains_inactive(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create(['is_active' => false]);

        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));
        $this->get(route('admin.profile.edit'))->assertOk()
            ->assertSee('cuenta inactiva');

        $this->assertFalse($target->fresh()->is_active);
        $this->assertAuthenticatedAs($target);
    }

    public function test_logout_during_impersonation_audits_end_and_clears_both_identities(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->assertFalse(app(ImpersonationManager::class)->active());
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $actor->id,
            'action' => 'impersonation.ended',
            'entity_id' => (string) $target->id,
        ]);
    }

    public function test_expired_impersonation_restores_actor_and_records_reason(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));

        config()->set('impersonation.max_minutes', 60);
        $this->travel(61)->minutes();
        $this->get(route('admin.profile.edit'))->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('warning');

        $this->assertAuthenticatedAs($actor);
        $ended = AdminAuditEvent::query()->where('action', 'impersonation.ended')->firstOrFail();
        $this->assertSame('expired', $ended->after['reason']);
    }

    public function test_deactivating_the_effective_user_ends_impersonation_after_the_action(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));

        $this->patch(route('admin.users.status', $target))->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('warning');

        $this->assertFalse($target->fresh()->is_active);
        $this->assertAuthenticatedAs($actor);
        $ended = AdminAuditEvent::query()->where('action', 'impersonation.ended')->firstOrFail();
        $this->assertSame('target_deactivated', $ended->after['reason']);
    }

    public function test_disabled_or_revoked_actor_cannot_keep_impersonation(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));
        $actor->forceFill(['is_active' => false])->save();

        $this->get(route('admin.profile.edit'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $ended = AdminAuditEvent::query()->where('action', 'impersonation.ended')->firstOrFail();
        $this->assertSame('actor_invalid', $ended->after['reason']);
    }

    public function test_revoking_impersonation_permission_ends_actor_session(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));
        $actorRole = Role::query()->where('key', 'administrator')->firstOrFail();
        $permission = Permission::query()->where('key', 'users.impersonate')->firstOrFail();
        $actorRole->permissions()->detach($permission);

        $this->get(route('admin.profile.edit'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $ended = AdminAuditEvent::query()->where('action', 'impersonation.ended')->firstOrFail();
        $this->assertSame('actor_invalid', $ended->after['reason']);
    }

    public function test_deleted_effective_user_restores_valid_actor_from_guest_redirect(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $targetId = $target->id;
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));
        $target->delete();

        $this->get(route('admin.profile.edit'))->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($actor);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $actor->id,
            'action' => 'impersonation.ended',
            'entity_id' => (string) $targetId,
        ]);
    }

    public function test_profile_password_change_during_impersonation_preserves_admin_and_invalidates_other_target_sessions(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create(['password' => 'Old-target-Password9']);
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));
        DB::table('sessions')->insert([
            'id' => 'other-target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.2',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->put(route('admin.profile.password'), [
            'password' => 'New-target-Password9',
            'password_confirmation' => 'New-target-Password9',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('New-target-Password9', $target->fresh()->password));
        $this->assertSame($actor->id, app(ImpersonationManager::class)->actor()?->id);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-target-session']);
        $passwordAudit = AdminAuditEvent::query()->where('action', 'user.password_changed')->firstOrFail();
        $this->assertSame($actor->id, $passwordAudit->actor_id);
        $this->assertSame($target->id, $passwordAudit->context['impersonation']['target_id']);

        $this->post(route('admin.impersonation.stop'));
        $this->assertAuthenticatedAs($actor);
    }

    public function test_role_administration_uses_real_actor_authority_and_audits_actor_context(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $operator = Role::query()->where('key', 'operator')->firstOrFail();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));
        $this->get(route('admin.roles.index'))->assertOk();
        $this->get(route('admin.permissions.index'))->assertOk();
        $this->get(route('admin.settings.index'))->assertForbidden();
        $usersView = Permission::query()->where('key', 'users.view')->firstOrFail();
        $this->put(route('admin.roles.permissions', $operator), [
            'permission_ids' => [$usersView->id],
        ])->assertRedirect();
        $this->assertTrue($operator->fresh()->permissions->contains('key', 'users.view'));
        $permissionAudit = AdminAuditEvent::query()->where('action', 'role.permissions_changed')->firstOrFail();
        $this->assertSame($actor->id, $permissionAudit->actor_id);
        $this->assertSame($target->id, $permissionAudit->context['impersonation']['target_id']);

        $this->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role_assignment_present' => '1',
            'role_ids' => [$operator->id],
        ])->assertRedirect();

        $this->assertTrue($target->fresh()->roles->contains('key', 'operator'));
        $this->assertAuthenticatedAs($target);
        $roleAudit = AdminAuditEvent::query()->where('action', 'user.roles_assigned')->firstOrFail();
        $this->assertSame($actor->id, $roleAudit->actor_id);
        $this->assertSame($target->id, $roleAudit->context['impersonation']['target_id']);
    }

    public function test_impersonation_permission_cannot_be_assigned_to_operator_role(): void
    {
        $actor = $this->adminUser();
        $operator = Role::query()->where('key', 'operator')->firstOrFail();
        $permission = Permission::query()->where('key', 'users.impersonate')->firstOrFail();

        $this->actingAsAdmin($actor)->put(route('admin.roles.permissions', $operator), [
            'permission_ids' => [$permission->id],
        ])->assertSessionHasErrors('permission_ids');

        $this->assertFalse($operator->fresh()->permissions()->where('permissions.key', 'users.impersonate')->exists());
    }

    public function test_sensitive_profile_changes_show_impersonation_marker_in_audit(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));

        $this->put(route('admin.profile.update'), ['name' => 'Nombre revisado'])->assertRedirect();
        $this->get(route('admin.audit.index'))->assertOk()->assertSee('Impersonación');

        $event = AdminAuditEvent::query()->where('action', 'user.profile_updated')->firstOrFail();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame((string) $target->id, $event->entity_id);
        $this->assertTrue($event->context['impersonation']['active']);
    }

    public function test_profile_document_upload_keeps_effective_owner_but_records_real_uploader(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target));

        $this->post(route('admin.profile.avatar.update'), [
            'file' => UploadedFile::fake()->image('target-avatar.png'),
        ])->assertRedirect();

        $avatar = $target->fresh()->avatar;
        $this->assertSame($target->id, $avatar?->documentable->id);
        $this->assertSame($actor->id, $avatar?->uploaded_by);
        $uploadAudit = AdminAuditEvent::query()->where('action', 'document.uploaded')->firstOrFail();
        $this->assertSame($actor->id, $uploadAudit->actor_id);
        $this->assertSame($target->id, $uploadAudit->context['impersonation']['target_id']);
    }
}
