<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class AdminSettingsAndActivityTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_settings_hub_only_shows_cards_the_user_can_open(): void
    {
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Gestiona las personas con acceso al sistema.')
            ->assertSee('Consulta entregas, reintentos y mensajes del sistema.')
            ->assertSee('Auditoría del sistema')
            ->assertSee('Roles')
            ->assertDontSee('Permisos avanzados')
            ->assertDontSee('Actividad administrativa');

        $reader = $this->userWithPermissions(['users.view']);
        $this->actingAs($reader)->withSession(['auth_session_version' => $reader->session_version])
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Usuarios')
            ->assertDontSee('Notificaciones')
            ->assertDontSee('Auditoría del sistema')
            ->assertDontSee('Roles');

        $noSettingsAccess = $this->userWithPermissions(['dashboard.view']);
        $this->actingAs($noSettingsAccess)->withSession(['auth_session_version' => $noSettingsAccess->session_version])
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_profile_is_in_the_account_menu_and_roles_screen_stays_operational(): void
    {
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Administra las personas con acceso al sistema.')
            ->assertSee('Roles')
            ->assertSee('Crear usuario')
            ->assertSee('Mi perfil')
            ->assertSee('Cerrar sesión')
            ->assertDontSee('Permisos avanzados');

        $this->actingAsAdmin($admin)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('Define qué puede hacer cada tipo de usuario.')
            ->assertSee('Crear rol')
            ->assertDontSee('Permisos avanzados');

        $this->actingAsAdmin($admin)->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertSee('Configuración avanzada')
            ->assertSee('Permisos del sistema')
            ->assertSee('Capacidades definidas por el sistema que se asignan mediante roles.')
            ->assertDontSee('Crear permiso');

        $this->actingAsAdmin($admin)->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('Registro de cambios administrativos y acciones sensibles.')
            ->assertDontSee('Auditoría');
    }

    public function test_authenticated_activity_is_recorded_at_most_every_five_minutes(): void
    {
        $user = $this->adminUser();
        $this->travelTo(now()->startOfSecond());

        $this->actingAsAdmin($user)->get(route('admin.dashboard'))->assertOk();
        $firstActivity = $user->fresh()->last_activity_at;
        $this->assertNotNull($firstActivity);

        $this->travel(4)->minutes();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertTrue($firstActivity->equalTo($user->fresh()->last_activity_at));

        $this->travel(2)->minutes();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertTrue($user->fresh()->last_activity_at->greaterThan($firstActivity));
    }

    public function test_users_without_activity_have_a_neutral_accessible_indicator(): void
    {
        $admin = $this->adminUser();
        $neverSeen = User::factory()->create([
            'name' => 'Sin actividad',
            'email' => 'sin-actividad@example.test',
            'last_activity_at' => null,
        ]);

        $this->actingAsAdmin($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('activity-neutral')
            ->assertSee('Sin actividad registrada')
            ->assertSee('Usuario')
            ->assertSee('Rol')
            ->assertSee('Estado')
            ->assertSee('Acciones');

        $this->assertNull($neverSeen->fresh()->last_activity_at);
    }

    public function test_recent_activity_has_a_text_label_and_exact_accessible_time(): void
    {
        $admin = $this->adminUser();
        $recent = User::factory()->create([
            'name' => 'Actividad reciente',
            'email' => 'actividad-reciente@example.test',
        ]);
        $lastActivity = now()->subMinutes(4)->startOfMinute();
        DB::table('users')->where('id', $recent->id)->update(['last_activity_at' => $lastActivity]);

        $this->actingAsAdmin($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('activity-strong')
            ->assertSee('Hace 4 min')
            ->assertSee('Última actividad: '.$lastActivity->format('d/m/Y H:i'))
            ->assertSee('datetime="'.$lastActivity->toIso8601String().'"', false);
    }

    public function test_guest_requests_do_not_record_user_activity(): void
    {
        $user = User::factory()->create(['last_activity_at' => null]);

        $this->get(route('admin.login'))->assertOk();
        $this->assertNull($user->fresh()->last_activity_at);
    }
}
