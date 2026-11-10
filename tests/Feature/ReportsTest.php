<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Reports\CsvValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_hub_and_direct_routes_require_the_correct_permissions(): void
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('admin.login'));
        $this->actingAsAdmin()->get(route('admin.reports.index'))->assertOk()
            ->assertSee(route('admin.reports.users'))->assertSee(route('admin.reports.organizations'));

        $this->actingAsAdmin($this->userWithRole('operator'))->get(route('admin.reports.index'))->assertForbidden();
        $viewer = $this->userWithPermissions(['reports.view', 'users.view']);
        $this->actingAsAdmin($viewer)->get(route('admin.reports.index'))->assertOk()
            ->assertSee(route('admin.reports.users'))->assertDontSee(route('admin.reports.organizations'));
        $this->get(route('admin.reports.users'))->assertOk()->assertDontSee('Exportar CSV');
        $this->get(route('admin.reports.organizations'))->assertForbidden();
        $this->get(route('admin.reports.users.export'))->assertForbidden();

        $exporter = $this->userWithPermissions(['reports.export', 'organizations.view']);
        $this->actingAsAdmin($exporter)->get(route('admin.reports.organizations'))->assertForbidden();
        $this->get(route('admin.reports.users.export'))->assertForbidden();
        $this->get(route('admin.reports.organizations.export'))->assertOk();
    }

    public function test_users_filter_and_export_the_same_rows_with_safe_utf8_and_audit(): void
    {
        $actor = $this->adminUser();
        $this->actingAsAdmin($actor);
        $user = $this->userWithRole('operator', [
            'name' => '=Equipo México', 'email' => 'reporte@example.test',
            'created_at' => '2026-10-02 23:59:59', 'last_login_at' => '2026-10-10 12:34:56',
        ]);
        $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
        $this->userWithRole('operator', ['name' => 'Equipo México inactivo', 'is_active' => false, 'created_at' => '2026-10-02 10:00:00']);
        $this->userWithRole('operator', ['name' => 'Equipo México tarde', 'created_at' => '2026-10-03 00:00:00']);
        User::factory()->create(['name' => 'Equipo México sin rol', 'created_at' => '2026-10-02 10:00:00']);

        $filters = ['q' => 'Equipo', 'status' => 'active', 'role' => 'operator', 'from' => '2026-10-02', 'to' => '2026-10-02'];
        $this->get(route('admin.reports.users', $filters))->assertOk()->assertSee('1 resultado')
            ->assertSee('=Equipo México')->assertDontSee('Equipo México tarde')
            ->assertDontSee('Equipo México inactivo')->assertDontSee('Equipo México sin rol');
        $this->get(route('admin.reports.users', ['q' => 'reporte@example.test']))->assertOk()->assertSee('=Equipo México');
        $this->get(route('admin.reports.users', ['q' => 'Sin coincidencias']))->assertOk()->assertSee('Sin resultados');
        $this->assertDatabaseMissing('admin_audit_events', ['action' => 'report.users.exported']);

        $response = $this->get(route('admin.reports.users.export', $filters + ['page' => 2]));
        $response->assertOk()->assertDownload('usuarios-'.now()->toDateString().'.csv');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertTrue(mb_check_encoding($csv, 'UTF-8'));
        $rows = $this->csvRows($csv);
        $this->assertSame(['Nombre', 'Correo', 'Estado', 'Roles', 'Último login', 'Última actividad', 'Fecha de creación'], $rows[0]);
        $this->assertSame(["'=Equipo México", 'reporte@example.test', 'Activo', $user->roles()->orderBy('name')->pluck('name')->implode(' | '), '2026-10-10 12:34:56', '', '2026-10-02 23:59:59'], $rows[1]);
        $this->assertCount(2, $rows);

        $event = AdminAuditEvent::query()->where('action', 'report.users.exported')->sole();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertTrue($event->context['search_applied']);
        $this->assertArrayNotHasKey('q', $event->context['filters']);
        $this->assertSame('operator', $event->context['filters']['role']);
        $this->assertStringNotContainsString('reporte@example.test', json_encode($event->context, JSON_THROW_ON_ERROR));
    }

    public function test_user_pagination_keeps_filters_and_roles_are_eager_loaded(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(28)->create(['name' => 'Paginación', 'is_active' => true]);
        DB::enableQueryLog();
        $this->get(route('admin.reports.users', ['q' => 'Paginación', 'status' => 'active']))
            ->assertOk()->assertSee('28 resultados')->assertSee('status=active&amp;page=2', false);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $roleLoadQueries = array_filter($queries, fn (array $entry): bool => str_contains($entry['query'], '"role_user"."user_id" in ('));
        $this->assertCount(1, $roleLoadQueries);
        $this->get(route('admin.reports.users', ['q' => 'Paginación', 'status' => 'active', 'page' => 2]))
            ->assertOk()->assertSee('28 resultados');
    }

    public function test_organizations_filter_count_and_export_locations_without_tax_data(): void
    {
        $actor = $this->adminUser();
        $this->actingAsAdmin($actor);
        $org = Organization::factory()->create([
            'name' => '@Cantares', 'legal_name' => 'Fundación Águila', 'slug' => 'cantares',
            'phone' => '+525512345678', 'tax_id' => 'DATO-FISCAL-PRIVADO',
        ]);
        $org->forceFill(['created_at' => '2026-10-05 14:30:00'])->save();
        Location::factory()->for($org)->create(['name' => 'Norte']);
        Location::factory()->for($org)->create(['name' => 'Sur']);
        Organization::factory()->create(['name' => 'Águila sin sedes', 'slug' => 'sin-sedes', 'is_active' => false]);

        $filters = ['q' => 'Águila', 'status' => 'active', 'locations' => 'with', 'to' => '2026-10-05'];
        $this->get(route('admin.reports.organizations', $filters))->assertOk()->assertSee('1 resultado')
            ->assertSee('@Cantares')->assertDontSee('Águila sin sedes')->assertDontSee('DATO-FISCAL-PRIVADO');
        $this->get(route('admin.reports.organizations', ['locations' => 'without', 'status' => 'inactive']))
            ->assertOk()->assertSee('Águila sin sedes')->assertDontSee('@Cantares');
        $response = $this->get(route('admin.reports.organizations.export', $filters));
        $response->assertOk()->assertDownload('organizaciones-'.now()->toDateString().'.csv');
        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(['Nombre', 'Nombre legal', 'Estado', 'Sedes', 'Correo', 'Teléfono', 'Fecha de creación'], $rows[0]);
        $this->assertSame(["'@Cantares", 'Fundación Águila', 'Activa', '2', '', "'+525512345678", '2026-10-05 14:30:00'], $rows[1]);
        $this->assertCount(2, $rows);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'report.organizations.exported', 'actor_id' => $actor->id]);
    }

    public function test_impersonation_requires_permissions_for_both_identities_and_audits_real_actor(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithRole('operator');
        $this->actingAsAdmin($admin)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->get(route('admin.reports.users'))->assertForbidden();
        $this->get(route('admin.reports.users.export'))->assertForbidden();
        $this->post(route('admin.impersonation.stop'))->assertRedirect();

        $target = $this->userWithPermissions(['reports.view', 'reports.export', 'users.view']);
        $this->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->get(route('admin.reports.users.export'))->assertOk();
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'report.users.exported', 'actor_id' => $admin->id]);

        $this->post(route('admin.impersonation.stop'))->assertRedirect();
        $actorWithoutReports = $this->userWithPermissions(['users.impersonate']);
        $this->actingAsAdmin($actorWithoutReports)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->get(route('admin.reports.users.export'))->assertForbidden();
    }

    public function test_export_limit_invalid_filters_and_formula_neutralization(): void
    {
        $this->actingAsAdmin();
        config(['admin_reports.export_max_rows' => 1]);
        User::factory()->count(2)->create(['name' => 'Límite']);
        $this->get(route('admin.reports.users', ['q' => 'Límite']))->assertOk()->assertSee('El CSV incluirá la primera fila.');
        $response = $this->get(route('admin.reports.users.export', ['q' => 'Límite']));
        $this->assertCount(2, $this->csvRows($response->streamedContent()));
        $filters = ['from' => '2026-10-02', 'to' => '2026-10-01'];
        $this->get(route('admin.reports.users', $filters))->assertOk()->assertSee('La fecha final debe ser igual o posterior a la inicial.');
        $this->get(route('admin.reports.users.export', $filters))->assertUnprocessable();

        foreach (['=1+1', '+123', '-9', '@SUMA', '  =1+1', "\ttexto", "\rtexto", "\ntexto", '＝1+1', "\u{FEFF}=1+1"] as $value) {
            $this->assertSame("'".$value, CsvValue::format($value));
        }
        $this->assertSame('García', CsvValue::format('García'));
        $this->assertSame(2, CsvValue::format(2));
    }

    public function test_streaming_crosses_batches_in_order_without_repeating_rows(): void
    {
        $this->actingAsAdmin();
        config(['admin_reports.export_max_rows' => 255]);
        $users = User::factory()->count(260)->create(['name' => 'Lote de exportación']);
        $response = $this->get(route('admin.reports.users.export', ['q' => 'Lote de exportación']));
        $response->assertOk();
        $rows = array_slice($this->csvRows($response->streamedContent()), 1);

        $this->assertSame($users->take(255)->pluck('email')->all(), array_column($rows, 1));
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
