<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Support\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class SystemAuditTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_system_audit_entry_is_hidden_without_its_specific_permission(): void
    {
        $this->seedAccessCatalog();
        $reader = $this->userWithPermissions(['users.view']);

        $this->actingAs($reader)->withSession(['auth_session_version' => $reader->session_version])
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertDontSee('Auditoría del sistema');

        $this->actingAs($reader)->withSession(['auth_session_version' => $reader->session_version])
            ->get(route('admin.system-audit.index'))
            ->assertForbidden();

        $this->actingAs($reader)->withSession(['auth_session_version' => $reader->session_version])
            ->get(route('admin.system-status.index'))
            ->assertForbidden();
    }

    public function test_system_audit_hub_filters_each_tool_by_its_existing_permission(): void
    {
        $administrator = $this->adminUser();
        $this->actingAsAdmin($administrator)->get(route('admin.system-audit.index'))
            ->assertOk()
            ->assertSee('Actividad administrativa')
            ->assertSee('Seguridad de acceso')
            ->assertSee('Permisos del sistema')
            ->assertSee('Entregas')
            ->assertSee('Estado del sistema');

        $this->seedAccessCatalog();
        $viewer = $this->userWithPermissions(['system.audit.view', 'permissions.view']);

        $this->actingAs($viewer)->withSession(['auth_session_version' => $viewer->session_version])
            ->get(route('admin.system-audit.index'))
            ->assertOk()
            ->assertSee('Permisos del sistema')
            ->assertSee('Estado del sistema')
            ->assertDontSee('Seguridad de acceso')
            ->assertDontSee('Actividad administrativa')
            ->assertDontSee('Entregas');

        $this->actingAs($viewer)->withSession(['auth_session_version' => $viewer->session_version])
            ->get(route('admin.system-status.index'))
            ->assertOk()
            ->assertSee('Estado del sistema');

        $this->actingAs($viewer)->withSession(['auth_session_version' => $viewer->session_version])
            ->get(route('admin.audit.index'))
            ->assertForbidden();

        $this->actingAs($viewer)->withSession(['auth_session_version' => $viewer->session_version])
            ->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertSee('Permisos del sistema')
            ->assertSee('Capacidades definidas por el sistema que se asignan mediante roles.');
    }

    public function test_administrator_has_system_audit_permission_and_operator_does_not(): void
    {
        $administrator = $this->adminUser();
        $operator = $this->userWithRole('operator');

        $this->assertTrue($administrator->fresh()->can('system.audit.view'));
        $this->assertFalse($operator->fresh()->can('system.audit.view'));

        $this->seedAccessCatalog();
        $this->seedAccessCatalog();
        $this->assertSame(1, Permission::query()->where('key', 'system.audit.view')->count());
        $this->assertTrue(Role::query()->where('key', 'administrator')->firstOrFail()->permissions()->where('permissions.key', 'system.audit.view')->exists());
        $this->assertFalse(Role::query()->where('key', 'operator')->firstOrFail()->permissions()->where('permissions.key', 'system.audit.view')->exists());
        $this->assertArrayHasKey('system.audit.view', BasePermissions::DEFINITIONS);
    }

    public function test_system_status_reports_safe_indicators_and_outbox_counts_without_secrets(): void
    {
        $admin = $this->adminUser();
        $this->outboxMessage('pending');
        $this->outboxMessage('failed');

        $this->actingAsAdmin($admin)->get(route('admin.system-status.index'))
            ->assertOk()
            ->assertSee('Entorno')
            ->assertSee('Versión de Laravel')
            ->assertSee('Versión de PHP')
            ->assertSee('Driver de base de datos')
            ->assertSee('Conexión a base de datos')
            ->assertSee('Conectada')
            ->assertSee('Driver de colas')
            ->assertSee('Driver de caché')
            ->assertSee('Mailer configurado')
            ->assertSee('Mensajes outbox pendientes')
            ->assertSee('Mensajes outbox fallidos')
            ->assertSee('Última ejecución del scheduler')
            ->assertSee('No disponible')
            ->assertDontSee('sensitive-recipient@example.test')
            ->assertDontSee('APP_KEY')
            ->assertDontSee('DB_PASSWORD')
            ->assertDontSee('MAIL_PASSWORD')
            ->assertSee('Modo mantenimiento')
            ->assertDontSee('private payload')
            ->assertViewHas('indicators', fn (array $indicators): bool => $indicators['Mensajes outbox pendientes'] === 1
                && $indicators['Mensajes outbox fallidos'] === 1
                && $indicators['Modo mantenimiento'] === 'Inactivo');
    }

    private function outboxMessage(string $status): void
    {
        DB::table('outbox_messages')->insert([
            'uuid' => (string) Str::uuid(),
            'category' => 'test',
            'channel' => 'email',
            'recipient' => 'sensitive-recipient@example.test',
            'recipient_hash' => hash('sha256', 'sensitive-recipient@example.test'),
            'template' => 'test',
            'payload' => json_encode(['message' => 'private payload']),
            'status' => $status,
            'attempts' => 0,
            'retry_generation' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
