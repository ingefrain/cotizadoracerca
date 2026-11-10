<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Documents\DocumentOwnerAccess;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class OrganizationsAndLocationsTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public function test_administrator_can_create_update_filter_and_deactivate_organization_and_its_locations_remain(): void
    {
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin)->post(route('admin.organizations.store'), [
            'name' => 'Escuela Horizonte', 'legal_name' => 'Escuela Horizonte AC', 'email' => 'hola@example.test',
        ])->assertRedirect();
        $organization = Organization::query()->firstOrFail();
        $this->assertSame('escuela-horizonte', $organization->slug);
        $this->assertNotSame((string) $organization->id, $organization->uuid);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'organization.created', 'actor_id' => $admin->id, 'entity_id' => $organization->uuid]);
        $this->put(route('admin.organizations.update', $organization), ['name' => 'Escuela Horizonte Norte', 'slug' => 'horizonte-norte'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'organization.updated', 'actor_id' => $admin->id]);

        $location = Location::factory()->for($organization)->create(['name' => 'Plantel Centro']);
        $this->get(route('admin.organizations.index'))->assertOk()
            ->assertSee('action="'.route('admin.organizations.status', $organization).'"', false)
            ->assertSee('Desactivar organización')
            ->assertSee('El estado individual de cada sede se conservará.');
        $this->assertTrue($organization->fresh()->is_active);
        $this->patch(route('admin.organizations.status', $organization))->assertRedirect();
        $this->assertFalse($organization->fresh()->is_active);
        $this->assertTrue($location->fresh()->is_active);
        $this->assertFalse($location->fresh()->isOperationallyActive());
        $this->assertSame(1, $organization->fresh()->locations()->count());
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'organization.disabled', 'actor_id' => $admin->id]);

        $this->get(route('admin.organizations.index', ['q' => 'Horizonte', 'status' => 'inactive']))->assertOk()->assertSee('Escuela Horizonte Norte');
    }

    public function test_location_crud_requires_active_organization_and_tracks_audits_and_filters(): void
    {
        $admin = $this->adminUser();
        $organization = Organization::factory()->create(['name' => 'Asociación Norte', 'slug' => 'asociacion-norte']);
        $this->actingAsAdmin($admin)->post(route('admin.locations.store'), [
            'organization_id' => $organization->id, 'name' => 'Sede Central', 'code' => 'CENTRAL', 'city' => 'Monterrey', 'country_code' => 'mx', 'latitude' => '25.6866',
        ])->assertRedirect();
        $location = Location::query()->firstOrFail();
        $this->assertSame($organization->id, $location->organization_id);
        $this->assertSame('MX', $location->country_code);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'location.created', 'actor_id' => $admin->id, 'entity_id' => $location->uuid]);
        $this->put(route('admin.locations.update', $location), [
            'organization_id' => $organization->id, 'name' => 'Sede Central Norte', 'code' => 'CENTRAL', 'city' => 'Saltillo',
        ])->assertRedirect();
        $this->get(route('admin.locations.index', ['q' => 'Saltillo', 'organization' => $organization->uuid]))->assertOk()->assertSee('Sede Central Norte');
        $this->patch(route('admin.locations.status', $location))->assertRedirect();
        $this->assertFalse($location->fresh()->isOperationallyActive());
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'location.updated', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'location.disabled', 'actor_id' => $admin->id]);

        $organization->update(['is_active' => false]);
        $this->post(route('admin.locations.store'), ['organization_id' => $organization->id, 'name' => 'No permitida'])
            ->assertStatus(422);
    }

    public function test_operator_has_no_organization_or_location_access_and_permissions_are_admin_only(): void
    {
        $this->seedAccessCatalog();
        $operator = $this->userWithRole('operator');
        $adminRole = Role::query()->where('key', 'administrator')->firstOrFail();
        $operatorRole = Role::query()->where('key', 'operator')->firstOrFail();
        foreach (['organizations.view', 'organizations.create', 'organizations.update', 'organizations.disable', 'locations.view', 'locations.create', 'locations.update', 'locations.disable'] as $key) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $this->assertTrue($adminRole->permissions->contains('id', $permission->id));
            $this->assertFalse($operatorRole->permissions->contains('id', $permission->id));
        }
        $this->actingAs($operator)->withSession(['auth_session_version' => $operator->session_version])
            ->get(route('admin.organizations.index'))->assertForbidden();
        $this->get(route('admin.locations.index'))->assertForbidden();
        $this->get(route('admin.settings.index'))->assertForbidden();
    }

    public function test_logo_is_private_optimized_and_documents_require_owner_policy(): void
    {
        $admin = $this->adminUser();
        $organization = Organization::factory()->create(['name' => 'Instituto Luz', 'slug' => 'instituto-luz']);
        $this->actingAsAdmin($admin)->put(route('admin.organizations.update', $organization), [
            'name' => $organization->name, 'slug' => $organization->slug, 'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect();
        $document = Document::query()->where('category', 'organization.logo')->firstOrFail();
        $this->assertSame('image/webp', $document->mime_type);
        $this->get(route('admin.organizations.logo', $organization))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'max-age=300, private');

        $access = app(DocumentOwnerAccess::class);
        $this->assertTrue($access->allows($admin, $document));
        $this->assertTrue($access->scopeVisibleTo(Document::query(), $admin)->whereKey($document->id)->exists());
        $unprivileged = User::factory()->create(['is_active' => true]);
        $this->assertFalse($access->allows($unprivileged, $document));
        $this->actingAs($unprivileged)->withSession(['auth_session_version' => $unprivileged->session_version])
            ->get(route('admin.organizations.logo', $organization))->assertForbidden();

        $this->actingAsAdmin($admin)->delete(route('admin.organizations.logo.delete', $organization))->assertRedirect();
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.deleted', 'actor_id' => $admin->id]);
    }

    public function test_administrative_actions_during_impersonation_record_real_actor(): void
    {
        $actor = $this->adminUser();
        $target = User::factory()->create();
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->post(route('admin.organizations.store'), ['name' => 'Organización Impersonada'])
            ->assertRedirect();
        $event = AdminAuditEvent::query()->where('action', 'organization.created')->firstOrFail();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($target->id, $event->context['impersonation']['target_id']);
    }

    public function test_location_documents_are_scoped_through_the_registered_owner_policy(): void
    {
        $admin = $this->adminUser();
        $organization = Organization::factory()->create(['name' => 'Fundación Río', 'slug' => 'fundacion-rio']);
        $location = Location::factory()->for($organization)->create(['name' => 'Sede Río']);
        $document = app(DocumentService::class)->upload(
            UploadedFile::fake()->createWithContent('manual.txt', 'Contenido de prueba'),
            $location,
            $admin,
            'location.manual',
        );

        $access = app(DocumentOwnerAccess::class);
        $this->assertTrue($access->allows($admin, $document));
        $this->assertTrue($access->scopeVisibleTo(Document::query(), $admin)->whereKey($document->id)->exists());
        $this->actingAsAdmin($admin)->get(route('admin.documents.index'))
            ->assertOk()->assertSee('Sede — Sede Río')->assertSee('location.manual');
    }

    public function test_organization_and_location_lists_paginate(): void
    {
        $admin = $this->adminUser();
        $organization = Organization::factory()->create(['name' => 'Red de Escuelas', 'slug' => 'red-de-escuelas']);
        foreach (range(1, 16) as $number) {
            Organization::factory()->create(['name' => 'Organización '.$number, 'slug' => 'organizacion-'.$number]);
            Location::factory()->for($organization)->create(['name' => 'Sede '.$number]);
        }

        $this->actingAsAdmin($admin)->get(route('admin.organizations.index'))->assertOk()->assertSee('page=2', false);
        $this->get(route('admin.locations.index'))->assertOk()->assertSee('page=2', false);
    }
}
