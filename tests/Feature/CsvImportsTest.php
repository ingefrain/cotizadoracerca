<?php

namespace Tests\Feature;

use App\Imports\CsvSource;
use App\Models\AdminAuditEvent;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\ImportRun;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CsvImportsTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    private const ITEMS = "catalog_slug,code,name,slug,description_markdown,sort_order,is_active\n";

    private const ORGANIZATIONS = "name,legal_name,slug,email,phone,website,tax_id,notes,is_active\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CsvSource::DISK);
        $this->actingAsAdmin();
    }

    public function test_catalog_item_preview_and_apply_preserve_markdown_and_prevent_duplicate_apply(): void
    {
        $catalog = $this->catalog();
        $this->get(route('admin.settings.index'))->assertOk()->assertSee('Importación de datos');
        $this->get(route('admin.imports.index'))->assertOk()->assertSee('Elementos de catálogo');
        $this->get(route('admin.imports.create', 'catalog_items'))->assertOk()->assertSee('Validar CSV');
        $template = $this->get(route('admin.imports.template', 'catalog_items'))->assertDownload('catalog_items-plantilla.csv');
        $this->assertSame("\xEF\xBB\xBF".rtrim(self::ITEMS)."\r\n", $template->streamedContent());
        $run = $this->upload('catalog_items', "\xEF\xBB\xBF".$this->fixture('catalog-items-valid.csv'));
        $this->assertSame('ready', $run->status);
        $this->assertSame(';', $run->delimiter);
        $this->assertDatabaseEmpty('catalog_items');
        $this->get(route('admin.imports.show', $run))->assertOk()->assertSee('<script>')->assertSee('Importar 1 registros')
            ->assertDontSee('<script>', false);
        $this->post(route('admin.imports.apply', $run))->assertSessionHas('notify.type', 'success');
        $item = $catalog->items()->sole();
        $this->assertSame("**Hola**\n<script>", $item->description_markdown);
        $this->assertSame('cafe', $item->slug);
        $this->assertSame('001', $item->code);
        $this->assertFalse($item->is_active);
        $this->assertSame(2, $item->sort_order);
        $this->assertSame('completed', $run->refresh()->status);
        Storage::disk(CsvSource::DISK)->assertMissing($run->sourcePath());
        $this->post(route('admin.imports.apply', $run))->assertConflict();
        $this->assertDatabaseCount('catalog_items', 1);
        $audit = AdminAuditEvent::query()->where('entity_id', $run->uuid)->where('action', 'import.applied')->sole();
        $this->assertSame($run->created_by, $audit->actor_id);
        $this->assertStringNotContainsString('Hola', json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function test_duplicates_and_inactive_catalog_invalidate_the_whole_run(): void
    {
        $catalog = $this->catalog();
        Catalog::factory()->inactive()->create(['slug' => 'inactivo']);
        CatalogItem::factory()->for($catalog)->create(['slug' => 'existente']);
        $run = $this->upload('catalog_items', $this->fixture('catalog-items-duplicate-code.csv'));
        $this->assertSame('invalid', $run->status);
        $this->assertSame(3, $run->invalid_rows);
        $this->assertSame(['duplicate_csv', 'duplicate_db', 'parent_unavailable'], $run->errors()->orderBy('id')->pluck('error_code')->all());
        $this->get(route('admin.imports.show', $run))->assertOk()->assertSee('Errores de validación')->assertDontSee('Aplicar importación');
        $this->post(route('admin.imports.apply', $run))->assertConflict();
        $this->assertDatabaseCount('catalog_items', 1);
    }

    public function test_revalidation_blocks_new_conflicts_and_changed_files(): void
    {
        $run = $this->upload('organizations', self::ORGANIZATIONS."Nueva,,,,,,,,\nOtra,,,,,,,,\n");
        Organization::factory()->create(['slug' => 'otra']);
        $this->post(route('admin.imports.apply', $run))->assertSessionHas('notify.type', 'error');
        $this->assertSame('invalid', $run->refresh()->status);
        $this->assertDatabaseCount('organizations', 1);
        $run = $this->upload('organizations', self::ORGANIZATIONS."Nueva,,,,,,,,\n");
        Storage::disk(CsvSource::DISK)->put($run->sourcePath(), self::ORGANIZATIONS."Manipulada,,,,,,,,\n");
        $this->post(route('admin.imports.apply', $run))->assertSessionHas('notify.type', 'error');
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('source_changed', $run->errors()->sole()->error_code);
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_organizations_and_locations_use_separate_runs_and_active_parents(): void
    {
        $run = $this->upload('organizations', $this->fixture('organizations-valid.csv'));
        $this->assertSame('ready', $run->status);
        $this->assertDatabaseEmpty('organizations');
        $this->post(route('admin.imports.apply', $run))->assertSessionHas('notify.type', 'success');
        $org = Organization::query()->sole();
        $header = "organization_slug,name,code,email,phone,address_line_1,address_line_2,city,state,postal_code,country_code,latitude,longitude,is_active\n";
        $run = $this->upload('locations', $header."organizacion-norte,Central,A,,,,,,,,mx,19.5,-99.1,true\n");
        $this->assertSame('ready', $run->status);
        $this->assertDatabaseEmpty('locations');
        $this->post(route('admin.imports.apply', $run))->assertSessionHas('notify.type', 'success');
        $location = Location::query()->sole();
        $this->assertTrue($location->organization->is($org));
        $this->assertSame('MX', $location->country_code);
        $this->assertSame('19.5000000', $location->latitude);
        $run = $this->upload('locations', $header."organizacion-norte,Otra,A,,,,,,,,mx,91,-99.1,true\n");
        $this->assertSame(['between', 'duplicate_db'], $run->errors()->orderBy('id')->pluck('error_code')->all());
        $org->update(['is_active' => false]);
        $run = $this->upload('locations', $this->fixture('locations-inactive-organization.csv'));
        $this->assertSame('parent_unavailable', $run->errors()->sole()->error_code);
    }

    public function test_malformed_files_and_limits_are_reported(): void
    {
        config(['imports.max_rows' => 1, 'imports.max_size_kb' => 1]);
        foreach ([
            ["name,slug\nNuevo,nuevo\n", 'header'],
            [self::ORGANIZATIONS."Falla\xFF,,,,,,,,\n", 'encoding'],
            [self::ORGANIZATIONS."\"Falla,,,,,,,,\n", 'csv_syntax'],
            [self::ORGANIZATIONS."Uno,,,,,,,,\nDos,,,,,,,,\n", 'max_rows'],
            [self::ORGANIZATIONS, 'empty_rows'],
        ] as [$body, $reason]) {
            $run = $this->upload('organizations', $body);
            $this->assertSame('failed', $run->status);
            $this->assertSame($reason, $run->errors()->sole()->error_code);
        }
        $this->post(route('admin.imports.store', 'organizations'), [
            'csv' => UploadedFile::fake()->createWithContent('archivo.csv', str_repeat('a', 1100)),
        ])->assertSessionHasErrors('csv');
        $this->assertDatabaseEmpty('organizations');
    }

    public function test_owner_permissions_and_prune_are_enforced(): void
    {
        $run = $this->upload('organizations', self::ORGANIZATIONS."Nueva,,,,,,,,\n");
        $this->actingAsAdmin($this->userWithRole('administrator'));
        $this->get(route('admin.imports.show', $run))->assertNotFound();
        $this->post(route('admin.imports.apply', $run))->assertNotFound();
        $this->delete(route('admin.imports.destroy', $run))->assertNotFound();
        $this->actingAsAdmin($this->userWithRole('operator'));
        $this->get(route('admin.imports.index'))->assertForbidden();
        $this->actingAsAdmin($this->userWithPermissions(['imports.view', 'imports.create', 'imports.apply']));
        $this->get(route('admin.imports.template', 'organizations'))->assertForbidden();
        $this->delete(route('admin.imports.destroy', $run))->assertForbidden();
        $run->update(['expires_at' => now()->subHour()]);
        $this->actingAsAdmin($run->creator);
        $this->post(route('admin.imports.apply', $run))->assertConflict();
        $this->artisan('imports:prune --dry-run')->assertSuccessful();
        Storage::disk(CsvSource::DISK)->assertExists($run->sourcePath());
        $this->artisan('imports:prune')->assertSuccessful();
        $this->assertSame('expired', $run->refresh()->status);
        Storage::disk(CsvSource::DISK)->assertMissing($run->sourcePath());
    }

    public function test_a_database_failure_rolls_back_rows_inserted_earlier_in_the_same_run(): void
    {
        $run = $this->upload('organizations', self::ORGANIZATIONS."Primera,,,,,,,,\nSegunda,,,,,,,,\n");
        DB::unprepared("CREATE TEMP TRIGGER reject_import BEFORE INSERT ON organizations
            WHEN NEW.slug = 'segunda' BEGIN SELECT RAISE(ABORT, 'simulated constraint'); END");
        try {
            $this->post(route('admin.imports.apply', $run))->assertSessionHas('notify.type', 'error');
            $this->assertDatabaseEmpty('organizations');
            $this->assertSame('failed', $run->refresh()->status);
            $this->assertSame('database_conflict', $run->errors()->sole()->error_code);
        } finally {
            DB::unprepared('DROP TRIGGER reject_import');
        }
    }

    public function test_impersonation_requires_both_identities_and_audits_the_real_actor(): void
    {
        $actor = auth()->user();
        $target = $this->userWithPermissions(['imports.view', 'imports.create', 'organizations.create']);
        $this->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->assertAuthenticatedAs($target);
        $run = $this->upload('organizations', self::ORGANIZATIONS."Nueva,,,,,,,,\n");
        $this->assertSame($actor->id, $run->created_by);
        $this->assertSame($target->id, $run->effective_user_id);
        $this->post(route('admin.imports.apply', $run))->assertForbidden();
        $event = AdminAuditEvent::query()->where('action', 'import.validated')->sole();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($target->id, $event->context['impersonation']['target_id']);
        $this->delete(route('admin.imports.destroy', $run))->assertRedirectToRoute('admin.imports.index');
        $deleted = AdminAuditEvent::query()->where('action', 'import.deleted')->sole();
        $this->assertSame($actor->id, $deleted->actor_id);
        $this->assertSame($target->id, $deleted->context['impersonation']['target_id']);
        $this->post(route('admin.impersonation.stop'))->assertRedirect();
        $this->get(route('admin.imports.show', $run))->assertNotFound();
    }

    public function test_unapplied_runs_are_deleted_with_their_source_and_row_errors(): void
    {
        foreach ([
            'ready' => self::ORGANIZATIONS."Nueva,,,,,,,,\n",
            'invalid' => self::ORGANIZATIONS.",,,,,,,,1\n",
            'failed' => "header\n",
        ] as $status => $content) {
            $run = $this->upload('organizations', $content);
            $this->assertSame($status, $run->status);
            $this->get(route('admin.imports.index'))->assertSee('Acciones')->assertSee('Eliminar datos.csv');
            $this->get(route('admin.imports.show', $run))->assertSee('Se eliminará esta corrida y su archivo temporal.');
            Storage::disk(CsvSource::DISK)->assertExists($run->sourcePath());
            $this->delete(route('admin.imports.destroy', $run))->assertRedirectToRoute('admin.imports.index')
                ->assertSessionHas('notify.type', 'success');
            Storage::disk(CsvSource::DISK)->assertMissing($run->sourcePath());
            $this->assertModelMissing($run);
            $this->assertDatabaseMissing('import_row_errors', ['import_run_id' => $run->id]);
            $event = AdminAuditEvent::query()->where('action', 'import.deleted')->where('entity_id', $run->uuid)->sole();
            $this->assertSame(['type' => 'organizations', 'status' => $status], $event->before);
            $this->assertNull($event->after);
        }
        $this->get(route('admin.imports.index'))->assertSee('Todavía no hay importaciones.');
        $this->assertDatabaseEmpty('organizations');
    }

    public function test_completed_or_in_progress_runs_cannot_be_deleted(): void
    {
        $run = $this->upload('organizations', self::ORGANIZATIONS."Nueva,,,,,,,,\n");
        foreach (['validating', 'applying'] as $status) {
            $run->update(['status' => $status]);
            $this->delete(route('admin.imports.destroy', $run))->assertConflict();
            Storage::disk(CsvSource::DISK)->assertExists($run->sourcePath());
        }
        $run->update(['status' => 'ready']);
        $this->post(route('admin.imports.apply', $run))->assertSessionHas('notify.type', 'success');
        $this->get(route('admin.imports.index'))->assertSee('Aplicada')->assertDontSee('Eliminar datos.csv');
        $this->get(route('admin.imports.show', $run))->assertSee('Aplicada')->assertDontSee('Eliminar importación');
        $this->delete(route('admin.imports.destroy', $run))->assertConflict();
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertDatabaseCount('organizations', 1);
    }

    private function upload(string $type, string $content): ImportRun
    {
        $response = $this->post(route('admin.imports.store', $type), [
            'csv' => UploadedFile::fake()->createWithContent('datos.csv', $content),
        ]);
        $run = ImportRun::query()->latest('id')->firstOrFail();
        $response->assertRedirectToRoute('admin.imports.show', $run);

        return $run;
    }

    private function catalog(): Catalog
    {
        return Catalog::factory()->create(['slug' => 'publico']);
    }

    private function fixture(string $name): string
    {
        return str_replace("\r\n", "\n", file_get_contents(base_path('tests/Fixtures/Imports/'.$name)));
    }
}
