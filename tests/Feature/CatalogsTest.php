<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Document;
use App\Models\Organization;
use App\Services\Documents\DocumentOwnerAccess;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CatalogsTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_catalog_create_edit_filters_scope_and_operational_state(): void
    {
        $this->actingAsAdmin();
        $org = Organization::factory()->inactive()->create(['name' => 'Organización Norte', 'slug' => 'org-norte']);
        $this->get(route('admin.settings.index'))->assertOk()->assertSee('Administrar catálogos');
        $this->get(route('admin.catalogs.create'))->assertOk()->assertSee('data-markdown-preview', false);
        $this->post(route('admin.catalogs.store'), [
            'name' => 'Servicios únicos', 'organization' => $org->uuid, 'is_active' => '1',
            'description_markdown' => '**Descripción privada**', 'sort_order' => 2,
        ])->assertSessionHas('notify.message', 'Catálogo creado correctamente.');
        $catalog = Catalog::query()->sole();
        $this->assertTrue(Str::isUuid($catalog->uuid));
        $this->assertSame('servicios-unicos', $catalog->slug);
        $this->assertFalse($catalog->isOperationallyActive());
        $this->get(route('admin.catalogs.edit', $catalog))->assertOk()->assertSee('<strong>Descripción privada</strong>', false)
            ->assertSee('data-notify-flash="success"', false)->assertSee('Catálogo creado correctamente.');
        $this->get(route('admin.catalogs.edit', $catalog))->assertOk()->assertDontSee('Catálogo creado correctamente.');
        $this->get(route('admin.catalogs.edit', $catalog->id))->assertNotFound();
        $this->post(route('admin.catalogs.store'), ['name' => 'Otro', 'slug' => $catalog->slug, 'is_active' => 1])->assertSessionHasErrors('slug');

        $item = CatalogItem::factory()->for($catalog)->create(['name' => 'Elemento', 'slug' => 'elemento', 'is_active' => true]);
        $this->get(route('admin.catalogs.index', ['q' => 'Servicios', 'status' => 'active', 'organization' => $org->uuid]))
            ->assertOk()->assertSee('Inactivo por organización')->assertSee('Servicios únicos')->assertSee('<td>1</td>', false);
        $this->get(route('admin.catalogs.index', ['status' => 'inactive']))->assertOk()->assertDontSee('Servicios únicos');
        $this->put(route('admin.catalogs.update', $catalog), ['name' => 'Servicios globales', 'is_active' => 0])->assertRedirect();
        $catalog->refresh();
        $this->assertNull($catalog->organization_id);
        $this->assertTrue($catalog->isOperationallyActive());
        $this->patch(route('admin.catalogs.status', $catalog), ['is_active' => 0])->assertRedirect();
        $this->assertTrue($item->refresh()->is_active);
        $this->assertFalse($item->isOperationallyActive());
        $this->patch(route('admin.catalogs.status', $catalog), ['is_active' => 1])->assertRedirect();
        $this->assertTrue($item->refresh()->isOperationallyActive());
        foreach (['created', 'updated', 'disabled', 'enabled'] as $action) {
            $this->assertDatabaseHas('admin_audit_events', ['action' => 'catalog.'.$action, 'entity_id' => $catalog->uuid]);
        }
        $this->assertStringNotContainsString('Descripción privada', AdminAuditEvent::where('entity_type', 'catalog')->get()->toJson());
    }

    public function test_items_validate_scoped_identifiers_metadata_filters_and_status(): void
    {
        $this->actingAsAdmin();
        $catalog = $this->catalog('servicios');
        $other = $this->catalog('cursos');
        $this->get(route('admin.catalogs.items.create', $catalog))->assertOk()->assertSee('data-file-dropzone', false);
        $payload = ['name' => 'Clases', 'code' => 'A-1', 'is_active' => 1, 'sort_order' => -2, 'metadata' => '{"nivel":"Básico"}', 'description_markdown' => '**Descripción de clases**'];
        $this->post(route('admin.catalogs.items.store', $catalog), $payload)->assertRedirect();
        $item = $catalog->items()->sole();
        $this->assertTrue(Str::isUuid($item->uuid));
        $this->assertSame(['nivel' => 'Básico'], $item->metadata);
        $this->post(route('admin.catalogs.items.store', $catalog), $payload)->assertSessionHasErrors(['slug', 'code']);
        $this->post(route('admin.catalogs.items.store', $other), $payload)->assertRedirect();
        $this->post(route('admin.catalogs.items.store', $catalog), ['name' => 'Otro', 'is_active' => 1])->assertRedirect();
        $this->assertDatabaseHas('catalog_items', ['name' => 'Otro', 'code' => null]);
        $this->post(route('admin.catalogs.items.store', $catalog), ['name' => 'Metadata inválida', 'is_active' => 1, 'metadata' => '{"token":"ejemplo"}'])->assertSessionHasErrors('metadata');
        $this->get(route('admin.catalogs.items.edit', [$other, $item]))->assertNotFound();
        $this->get(route('admin.catalogs.items.edit', [$catalog, $item]))->assertOk()->assertSee('<strong>Descripción de clases</strong>', false);
        $this->get(route('admin.catalogs.items.index', $catalog).'?q=A-1&status=active')->assertOk()->assertSee('Clases')->assertDontSee('Otro');
        $this->get(route('admin.catalogs.items.index', $catalog))->assertOk()->assertSeeInOrder(['Clases', 'Otro']);
        foreach (range(1, 15) as $i) {
            CatalogItem::factory()->for($catalog)->create(['name' => 'Fila '.$i, 'slug' => 'fila-'.$i]);
        }
        $this->get(route('admin.catalogs.items.index', $catalog).'?q=Fila&status=active')->assertOk()->assertSee('Fila 15');
        $this->get(route('admin.catalogs.items.index', $catalog).'?status=active')->assertOk()->assertSee('status=active&amp;page=2', false);

        $this->put(route('admin.catalogs.items.update', [$catalog, $item]), ['name' => 'Clases actualizadas', 'is_active' => 0])->assertRedirect();
        $this->assertTrue($item->refresh()->is_active);
        $this->patch(route('admin.catalogs.items.status', [$catalog, $item]), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($item->refresh()->is_active);
        $this->patch(route('admin.catalogs.items.status', [$catalog, $item]), ['is_active' => 1])->assertRedirect();
        $this->assertTrue($item->refresh()->is_active);
        foreach (['created', 'updated', 'disabled', 'enabled'] as $action) {
            $this->assertDatabaseHas('admin_audit_events', ['action' => 'catalog_item.'.$action, 'entity_id' => $item->uuid]);
        }
        $this->assertStringNotContainsString('Descripción de clases', AdminAuditEvent::where('entity_type', 'catalog_item')->get()->toJson());
    }

    public function test_private_images_and_document_policies_follow_the_owner(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin);
        $catalog = $this->catalog('imagen');
        $org = Organization::factory()->create(['name' => 'Sede privada', 'slug' => 'sede-privada']);
        $catalog->update(['organization_id' => $org->id]);
        $this->post(route('admin.catalogs.items.store', $catalog), [
            'name' => 'Con imagen', 'is_active' => 1, 'image' => UploadedFile::fake()->image('principal.png', 40, 40),
        ])->assertRedirect();
        $item = $catalog->items()->sole();
        $image = $item->image;
        $this->assertSame('image/webp', $image->mime_type);
        Storage::disk('local')->assertExists($image->path);
        $other = app(DocumentService::class)->upload(UploadedFile::fake()->createWithContent('ficha.txt', 'Ficha de prueba'), $item, $admin, 'ficha');
        $catalogDoc = app(DocumentService::class)->upload(UploadedFile::fake()->createWithContent('catalogo.txt', 'Catálogo de prueba'), $catalog, $admin, 'ficha');
        $this->assertTrue($catalog->documents()->whereKey($catalogDoc)->exists());
        $this->assertSame($image->id, $item->fresh()->image->id);
        $this->get(route('admin.catalogs.items.index', $catalog))->assertOk()->assertSee(route('admin.catalogs.items.image', [$catalog, $item]));
        $this->get(route('admin.catalogs.items.image', [$catalog, $item]))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
        $access = app(DocumentOwnerAccess::class);
        $viewer = $this->userWithPermissions(['catalogs.view', 'catalog_items.view', 'documents.view', 'documents.download']);
        $this->assertFalse($access->allows($viewer, $image));
        $this->assertFalse($access->scopeVisibleTo(Document::query(), $viewer)->whereKey([$image->id, $other->id, $catalogDoc->id])->exists());
        $this->assertTrue($access->scopeVisibleTo(Document::query(), $admin)->whereKey($image)->exists());
        $this->assertFalse($access->canManage($admin, $image));
        $this->actingAsAdmin($viewer)->get(route('admin.catalogs.items.image', [$catalog, $item]))->assertForbidden();
        $this->get(route('admin.documents.download', $catalogDoc))->assertForbidden();

        $this->actingAsAdmin($admin)->put(route('admin.catalogs.items.update', [$catalog, $item]), [
            'name' => $item->name, 'image' => UploadedFile::fake()->image('reemplazo.jpg', 48, 48),
        ])->assertRedirect();
        $replacement = $item->fresh()->image;
        $this->assertNotSame($image->id, $replacement->id);
        $this->assertSoftDeleted($image);
        Storage::disk('local')->assertExists($image->path);
        $this->delete(route('admin.catalogs.items.image.delete', [$catalog, $item]))->assertRedirect();
        $this->assertNull($item->fresh()->image);
        $this->assertSoftDeleted($replacement);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.deleted', 'actor_id' => $admin->id]);
    }

    public function test_catalog_permissions_and_impersonation_require_both_identities(): void
    {
        $admin = $this->adminUser();
        $operator = $this->userWithRole('operator');
        $this->actingAsAdmin($operator)->get(route('admin.catalogs.index'))->assertForbidden();
        $target = $this->userWithPermissions(['catalogs.view', 'catalogs.create']);
        $this->actingAsAdmin($admin)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->post(route('admin.catalogs.store'), ['name' => 'Impersonado', 'is_active' => 1])->assertRedirect();
        $event = AdminAuditEvent::where('action', 'catalog.created')->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame($target->id, $event->context['impersonation']['target_id']);
        $this->put(route('admin.catalogs.update', Catalog::sole()), ['name' => 'No autorizado'])->assertForbidden();
        $this->post(route('admin.impersonation.stop'))->assertRedirect();

        $actor = $this->userWithPermissions(['users.impersonate', 'catalogs.view']);
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->post(route('admin.catalogs.store'), ['name' => 'Sin permiso', 'is_active' => 1])->assertForbidden();
        $this->post(route('admin.impersonation.stop'))->assertRedirect();
        config(['catalogs.enabled' => false]);
        $this->actingAsAdmin($admin)->get(route('admin.settings.index'))->assertOk()->assertDontSee('Administrar catálogos');
        $this->get(route('admin.catalogs.index'))->assertForbidden();
    }

    public function test_status_toggle_requires_csrf_and_permissions_and_returns_the_saved_state(): void
    {
        $admin = $this->adminUser();
        $catalog = $this->catalog('toggle');
        $item = CatalogItem::factory()->for($catalog)->create(['name' => 'Elemento', 'slug' => 'elemento', 'is_active' => true]);
        $catalogUrl = route('admin.catalogs.status', $catalog);
        $itemUrl = route('admin.catalogs.items.status', [$catalog, $item]);
        $this->actingAsAdmin($this->userWithRole('operator'))->patchJson($catalogUrl, ['is_active' => false])->assertForbidden();
        $this->patchJson($itemUrl, ['is_active' => false])->assertForbidden();

        $this->actingAsAdmin($admin)->get(route('admin.catalogs.edit', $catalog))->assertOk()
            ->assertSee('role="switch"', false)->assertSee('aria-checked="true"', false);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->patchJson($catalogUrl, ['is_active' => false])->assertStatus(419);
        $this->assertTrue($catalog->refresh()->is_active);
        $headers = ['X-CSRF-TOKEN' => session()->token()];
        $this->patchJson($catalogUrl, ['is_active' => 'wrong'], $headers)->assertUnprocessable();
        $this->patchJson($catalogUrl, ['is_active' => false, 'name' => 'Ignorado'], $headers)
            ->assertOk()->assertExactJson(['success' => true, 'is_active' => false, 'label' => 'Inactivo']);
        $this->assertSame('Toggle', $catalog->refresh()->name);
        $this->assertFalse($catalog->is_active);
        $this->assertTrue($item->refresh()->is_active);
        $this->assertFalse($item->isOperationallyActive());
        $this->patchJson($catalogUrl, ['is_active' => true], $headers)->assertOk()->assertJsonPath('is_active', true);
        $this->assertTrue($catalog->refresh()->is_active);

        $target = $this->userWithPermissions(['catalogs.view', 'catalog_items.view', 'catalog_items.disable']);
        $this->post(route('admin.users.impersonate', $target), ['_token' => session()->token()])->assertRedirect();
        $headers = ['X-CSRF-TOKEN' => session()->token()];
        $this->patchJson($catalogUrl, ['is_active' => false], $headers)->assertForbidden();
        $this->patchJson($itemUrl, ['is_active' => false], $headers)->assertOk()->assertJsonPath('is_active', false);
        $this->assertFalse($item->refresh()->is_active);
        $this->patchJson($itemUrl, ['is_active' => true], $headers)->assertOk()->assertJsonPath('is_active', true);
        $this->assertTrue($item->refresh()->is_active);
        foreach (['catalog.disabled', 'catalog.enabled', 'catalog_item.disabled', 'catalog_item.enabled'] as $action) {
            $event = AdminAuditEvent::where('action', $action)->sole();
            $this->assertSame($admin->id, $event->actor_id);
            if (str_starts_with($action, 'catalog_item.')) {
                $this->assertSame($target->id, $event->context['impersonation']['target_id']);
            }
        }
    }

    private function catalog(string $slug): Catalog
    {
        return Catalog::factory()->create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    }
}
