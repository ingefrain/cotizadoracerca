<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class ComponentCatalogTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
        config(['admin_components.enabled' => true]);
    }

    public function test_catalog_uses_configured_cards_and_is_linked_in_sidebar(): void
    {
        $this->actingAsAdmin()->get(route('admin.dashboard'))->assertSee(route('admin.components.index'));
        $response = $this->get(route('admin.components.index'))->assertOk();
        foreach (config('admin_components.cards') as $card) {
            $response->assertSee($card['title'])->assertSee(route($card['route']));
        }

        config(['admin_components.cards' => [
            ['title' => 'Ejemplo registrado', 'description' => 'Descripción de prueba', 'route' => 'admin.components.markdown'],
        ]]);
        $this->get(route('admin.components.index'))->assertSee('Ejemplo registrado')->assertDontSee('Selector de roles');
    }

    public function test_catalog_requires_login_and_technical_permission(): void
    {
        foreach (['index', 'markdown', 'dropzone', 'password', 'modal', 'multi-select', 'confirmations', 'utilities', 'charts'] as $demo) {
            $this->get(route('admin.components.'.$demo))->assertRedirect(route('admin.login'));
        }
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAsAdmin($user)->get(route('admin.profile.edit'))->assertDontSee(route('admin.components.index'));
        $this->get(route('admin.components.markdown'))->assertForbidden();
        $this->get(route('admin.components.confirmations'))->assertForbidden();
        $this->get(route('admin.components.charts'))->assertForbidden();
    }

    public function test_catalog_is_hidden_and_blocked_when_disabled_or_outside_local(): void
    {
        $this->actingAsAdmin();
        config(['admin_components.enabled' => false]);
        $this->get(route('admin.dashboard'))->assertDontSee(route('admin.components.index'));
        $this->get(route('admin.components.index'))->assertNotFound();
        config(['admin_components.enabled' => true]);
        $this->app->instance('env', 'production');
        $this->get(route('admin.dashboard'))->assertDontSee(route('admin.components.index'));
        $this->get(route('admin.components.password'))->assertNotFound();
        $this->get(route('admin.components.charts'))->assertNotFound();
    }

    public function test_utilities_groups_the_toggle_and_notify_and_retires_the_old_route(): void
    {
        $this->actingAsAdmin();
        $this->get(route('admin.components.index'))->assertOk()->assertSee('Utilidades')->assertDontSee('Toggle de estado');
        $page = $this->get(route('admin.components.utilities'))->assertOk()->assertSee('Toggle de estado')->assertSee('Notificaciones');
        $page->assertSee('aria-busy="true"', false)->assertDontSee('data-toggle-feedback', false)->assertDontSee('Guardando…');
        foreach (['success', 'info', 'warning', 'error'] as $type) {
            $page->assertSee('data-notify-trigger="'.$type.'"', false)
                ->assertSee('data-notify-template="'.$type.'"', false);
        }
        $this->get('/admin/components/status-toggle')->assertNotFound();
    }

    public function test_charts_demo_renders_four_accessible_examples_in_local(): void
    {
        $this->actingAsAdmin();
        $page = $this->get(route('admin.components.charts'))->assertOk()
            ->assertSee('Datos demostrativos.')
            ->assertSee('50.00 %');
        foreach (['bar', 'line', 'doughnut', 'pie'] as $type) {
            $page->assertSee('"type":"'.$type.'"', false);
        }
        $this->assertSame(4, substr_count($page->getContent(), '<summary>Ver datos</summary>'));
    }

    public function test_flash_notify_escapes_text_and_falls_back_to_info_for_an_invalid_type(): void
    {
        $this->actingAsAdmin();
        $message = '<img src=x onerror=alert(1)>';
        $this->withSession(['notify' => ['type' => 'unknown', 'message' => $message]])
            ->get(route('admin.components.utilities'))->assertOk()
            ->assertSee('data-notify-flash="info"', false)
            ->assertSee($message)
            ->assertDontSee($message, false);
    }

    public function test_demos_render_existing_controls_without_write_routes_or_fixtures(): void
    {
        $this->actingAsAdmin();
        $roles = DB::table('roles')->count();
        $this->get(route('admin.components.markdown'))->assertOk()->assertSee('data-preview-visible="true"', false);
        $this->get(route('admin.components.dropzone'))->assertOk()->assertSee('data-file-dropzone', false);
        $this->get(route('admin.components.password'))->assertOk()->assertSee('data-password-policy', false);
        $this->get(route('admin.components.modal'))->assertOk()->assertSee('data-modal-initial-focus', false);
        $this->get(route('admin.components.multi-select'))->assertOk()->assertSee('Profesor')->assertSee('data-role-selector', false);
        $this->post(route('admin.components.dropzone'))->assertMethodNotAllowed();
        $this->post(route('admin.components.password'))->assertMethodNotAllowed();
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('roles', $roles);
        $this->get('/admin/dev/markdown')->assertNotFound();
    }

    public function test_confirmation_demo_renders_accessible_variants_without_a_write_endpoint(): void
    {
        $this->actingAsAdmin();
        $this->get(route('admin.components.confirmations'))->assertOk()
            ->assertSee('aria-labelledby="demo-confirm-warning-title"', false)
            ->assertSee('aria-describedby="demo-confirm-warning-description"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('Los avisos de este ejemplo se suspenderán temporalmente.')
            ->assertSee('El archivo permanecerá durante el periodo de retención.')
            ->assertSee('Seguir editando')
            ->assertSee('data-confirm-phrase="ELIMINAR"', false)
            ->assertSee('data-confirm-accept disabled', false)
            ->assertSee('data-variant="default"', false)
            ->assertSee('data-variant="info"', false)
            ->assertSee('data-variant="warning"', false)
            ->assertSee('data-variant="danger"', false)
            ->assertSee('data-variant="danger-confirm"', false)
            ->assertDontSee('id="demo-confirm-delete-phrase"', false)
            ->assertSee('id="demo-confirm-purge-phrase"', false)
            ->assertSee('alert-success')->assertSee('alert-info')->assertSee('alert-warning')->assertSee('alert-error')
            ->assertDontSee('<form method="POST" action="…">', false);
        $this->post(route('admin.components.confirmations'))->assertMethodNotAllowed();
    }

    public function test_user_confirmation_preserves_post_patch_csrf_and_leaves_account_unchanged_on_render(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['name' => 'Usuario de ejemplo', 'is_active' => true]);

        $page = $this->get(route('admin.users.index'))->assertOk();
        $page->assertSee('action="'.route('admin.users.impersonate', $target).'"', false)
            ->assertSee('Iniciar impersonación')
            ->assertSee('action="'.route('admin.users.status', $target).'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('sus sesiones activas se invalidarán')
            ->assertDontSee('onsubmit=', false);
        $this->assertTrue($target->refresh()->is_active);
    }
}
