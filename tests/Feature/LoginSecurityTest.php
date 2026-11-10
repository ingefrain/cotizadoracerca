<?php

namespace Tests\Feature;

use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\User;
use App\Support\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_login_records_success_invalid_credentials_and_inactive_account_without_password_material(): void
    {
        $active = $this->adminUser(['password' => 'Correcta-123!']);
        $inactive = User::factory()->create(['is_active' => false, 'password' => 'Correcta-123!']);

        $this->withHeaders(['User-Agent' => str_repeat('A', 600)])
            ->post(route('admin.login.store'), ['email' => strtoupper($active->email), 'password' => 'Correcta-123!'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertNull($active->fresh()->last_activity_at);
        $this->post(route('admin.logout'));
        $this->post(route('admin.login.store'), ['email' => $active->email, 'password' => 'Incorrecta-123!'])
            ->assertSessionHasErrors('email');
        $this->post(route('admin.login.store'), ['email' => $inactive->email, 'password' => 'Correcta-123!'])
            ->assertSessionHasErrors('email');

        $attempts = LoginAttempt::query()->oldest('id')->get();
        $this->assertSame([
            LoginAttempt::SUCCESS,
            LoginAttempt::INVALID_CREDENTIALS,
            LoginAttempt::INACTIVE_ACCOUNT,
        ], $attempts->pluck('result')->all());
        $this->assertSame($active->id, $attempts[0]->user_id);
        $this->assertSame($active->email, $attempts[0]->email);
        $this->assertSame($inactive->id, $attempts[2]->user_id);
        $this->assertSame('127.0.0.1', $attempts[0]->ip_address);
        $this->assertNotNull($attempts[0]->created_at);
        $this->assertSame(512, mb_strlen($attempts[0]->user_agent));
        $this->assertFalse(Schema::hasColumn('login_attempts', 'password'));
        $this->assertFalse(Schema::hasColumn('login_attempts', 'token'));

        $active->refresh();
        $this->assertNotNull($active->last_login_at);
        $this->assertSame('127.0.0.1', $active->last_login_ip);
        $this->assertNull($inactive->fresh()->last_login_at);
        $this->assertNull($inactive->fresh()->last_login_ip);
        $this->assertNotNull($active->fresh()->last_activity_at);
        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_rate_limited_login_creates_a_safe_technical_attempt(): void
    {
        $email = 'rate+'.Str::uuid().'@example.test';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('admin.login.store'), ['email' => $email, 'password' => 'Secret-not-to-store'])
                ->assertSessionHasErrors('email');
        }

        $this->post(route('admin.login.store'), ['email' => $email, 'password' => 'Secret-not-to-store'])
            ->assertTooManyRequests();

        $this->assertSame(5, LoginAttempt::query()->where('result', LoginAttempt::INVALID_CREDENTIALS)->count());
        $limited = LoginAttempt::query()->where('result', LoginAttempt::RATE_LIMITED)->sole();
        $this->assertSame('rate_limit_exceeded', $limited->reason);
        $this->assertSame($email, $limited->email);
        $this->assertNull($limited->user_id);
    }

    public function test_access_screen_is_permission_protected_and_filters_with_pagination(): void
    {
        $this->seedAccessCatalog();
        $operator = $this->userWithRole('operator');
        $this->actingAs($operator)->withSession(['auth_session_version' => $operator->session_version])
            ->get(route('admin.login-security.index'))
            ->assertForbidden();

        $reader = $this->userWithPermissions(['security.login.view']);
        $this->assertTrue(Role::query()->where('key', 'administrator')->firstOrFail()->permissions()
            ->where('permissions.key', 'security.login.view')->exists());
        $this->assertFalse(Role::query()->where('key', 'operator')->firstOrFail()->permissions()
            ->where('permissions.key', 'security.login.view')->exists());
        $this->assertArrayHasKey('security.login.view', BasePermissions::DEFINITIONS);

        for ($index = 0; $index < 30; $index++) {
            $this->createAttempt("page-{$index}@example.test", LoginAttempt::INVALID_CREDENTIALS, '10.0.0.8');
        }
        for ($index = 0; $index < 5; $index++) {
            $this->createAttempt('target@example.test', LoginAttempt::INVALID_CREDENTIALS, '127.0.0.1');
        }
        $this->createAttempt('target@example.test', LoginAttempt::SUCCESS, '127.0.0.1');

        $this->actingAs($reader)->withSession(['auth_session_version' => $reader->session_version])
            ->get(route('admin.login-security.index', ['result' => LoginAttempt::INVALID_CREDENTIALS, 'search' => 'target@', 'ip' => '127.0.0.1']))
            ->assertOk()
            ->assertSee('target@example.test')
            ->assertDontSee('page-0@example.test')
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['failures'] === 35
                && $metrics['successes'] === 1
                && $metrics['repeated_accounts'] === 1)
            ->assertViewHas('attempts', fn ($attempts): bool => $attempts->total() === 5 && $attempts->count() === 5);

        $this->get(route('admin.login-security.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('attempts', fn ($attempts): bool => $attempts->total() === 36 && $attempts->currentPage() === 2);
    }

    public function test_login_attempt_pruning_respects_configured_retention_and_dry_run(): void
    {
        config(['security.login_attempt_retention_days' => 30]);
        LoginAttempt::query()->create([
            'email' => 'old@example.test', 'result' => LoginAttempt::INVALID_CREDENTIALS,
            'reason' => 'credentials_rejected', 'created_at' => now()->subDays(31),
        ]);
        LoginAttempt::query()->create([
            'email' => 'recent@example.test', 'result' => LoginAttempt::SUCCESS,
            'reason' => 'authenticated', 'created_at' => now()->subDays(29),
        ]);

        $this->artisan('security:prune-login-attempts --dry-run')->expectsOutputToContain('retención: 30 días): 1')->assertExitCode(0);
        $this->assertSame(2, LoginAttempt::query()->count());

        $this->artisan('security:prune-login-attempts')->expectsOutputToContain('Registros eliminados')->assertExitCode(0);
        $this->assertDatabaseMissing('login_attempts', ['email' => 'old@example.test']);
        $this->assertDatabaseHas('login_attempts', ['email' => 'recent@example.test']);
    }

    public function test_maintenance_page_is_neutral_and_native_response_is_503_with_retry_headers(): void
    {
        config([
            'app.name' => 'Cotizador Acerca Demo',
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'array',
            'maintenance_page.message' => 'Estamos realizando una actualización.',
            'maintenance_page.secondary_message' => 'Volveremos pronto.',
            'maintenance_page.logo_url' => null,
            'maintenance_page.refresh_seconds' => 30,
        ]);

        $html = view('errors.503')->render();
        $this->assertStringContainsString('Cotizador Acerca Demo', $html);
        $this->assertStringContainsString('Estamos realizando una actualización.', $html);
        $this->assertStringContainsString('Volveremos pronto.', $html);
        $this->assertStringContainsString('<meta http-equiv="refresh" content="30">', $html);
        $this->assertStringNotContainsString('APP_KEY', $html);
        $this->assertStringNotContainsString('DB_PASSWORD', $html);
        $this->assertStringNotContainsString('stack trace', strtolower($html));

        $maintenance = app()->maintenanceMode();
        $maintenance->activate(['template' => $html, 'status' => 503, 'retry' => 60, 'refresh' => 30]);

        try {
            $this->get(route('admin.login'))
                ->assertStatus(503)
                ->assertHeader('Retry-After', '60')
                ->assertHeader('Refresh', '30')
                ->assertSee('Cotizador Acerca Demo');
        } finally {
            $maintenance->deactivate();
        }

        $this->assertFalse($maintenance->active());
    }

    private function createAttempt(string $email, string $result, string $ip): void
    {
        LoginAttempt::query()->create([
            'email' => $email,
            'ip_address' => $ip,
            'result' => $result,
            'reason' => $result === LoginAttempt::SUCCESS ? 'authenticated' : 'credentials_rejected',
            'created_at' => now(),
        ]);
    }
}
