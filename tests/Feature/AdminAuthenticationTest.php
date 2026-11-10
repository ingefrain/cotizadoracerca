<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminLoginLimiter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_guest_can_open_login_and_password_recovery(): void
    {
        $response = $this->get(route('admin.login'));
        $response->assertOk()->assertSee('Iniciar sesión');
        $this->get(route('admin.password.request'))
            ->assertOk()
            ->assertSee('Restablecer contraseña')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/')->assertRedirect(route('admin.login'));
    }

    public function test_password_reset_form_is_not_cached_or_indexed(): void
    {
        $user = $this->adminUser();
        $response = $this->get(route('admin.password.reset', [
            'token' => 'sample-token',
            'email' => $user->email,
        ]));

        $response->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('name="password_confirmation"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_active_user_can_login_with_remember_and_session_version(): void
    {
        $user = $this->adminUser(['password' => 'Una-clave-segura-123!']);

        $this->post(route('admin.login.store'), [
            'email' => strtoupper($user->email),
            'password' => 'Una-clave-segura-123!',
            'remember' => '1',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->assertSame($user->session_version, session('auth_session_version'));
    }

    public function test_authenticated_user_is_redirected_to_profile_from_guest_routes(): void
    {
        $admin = $this->adminUser();

        $this->actingAsAdmin($admin)->get(route('admin.login'))
            ->assertRedirect(route('admin.profile.edit'));
    }

    public function test_invalid_credentials_are_generic_and_inactive_users_cannot_login(): void
    {
        $active = $this->adminUser(['password' => 'Una-clave-segura-123!']);
        $inactive = User::factory()->create(['is_active' => false, 'password' => 'Una-clave-segura-123!']);

        foreach ([$active, $inactive] as $user) {
            $this->from(route('admin.login'))->post(route('admin.login.store'), [
                'email' => $user->email,
                'password' => $user === $active ? 'incorrecta' : 'Una-clave-segura-123!',
            ])->assertRedirect(route('admin.login'))->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    public function test_login_is_limited_by_account_and_ip(): void
    {
        $this->freezeTime();
        $email = 'rate+'.Str::uuid().'@example.test';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('admin.login.store'), ['email' => $email, 'password' => 'incorrecta'])
                ->assertSessionHasErrors('email');
        }

        $payload = ['email' => $email, 'password' => 'incorrecta'];
        $this->post(route('admin.login.store'), $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-login-retry="60"', false)
            ->assertSee(route('admin.password.request'))
            ->assertDontSee('incorrecta');

        $this->travel(15)->seconds();
        $this->withHeader('X-Forwarded-For', '192.0.2.12')
            ->post(route('admin.login.store'), $payload)
            ->assertTooManyRequests()->assertHeader('Retry-After', '45');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.13'])
            ->post(route('admin.login.store'), $payload)->assertSessionHasErrors('email');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->travel(46)->seconds();
        $this->post(route('admin.login.store'), $payload)->assertSessionHasErrors('email');
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->actingAsAdmin();
        $token = session()->token();

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->assertNotSame($token, session()->token());
    }

    public function test_invalid_forms_do_not_consume_login_quota(): void
    {
        $email = 'valid@example.test';
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('admin.login.store'), ['email' => $email])->assertSessionHasErrors('password');
        }

        $this->post(route('admin.login.store'), ['email' => $email, 'password' => 'incorrecta'])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseCount('login_attempts', 1);
    }

    public function test_identity_clear_preserves_ip_failures(): void
    {
        $this->freezeTime();
        $limiter = app(AdminLoginLimiter::class);
        $ip = '127.0.0.1';
        $user = $this->adminUser(['password' => 'Una-clave-segura-123!']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $limiter->recordFailure($user->email, $ip);
        }
        $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => 'Una-clave-segura-123!'])
            ->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.logout'))->assertRedirect();
        $limiter->recordFailure($user->email, $ip);
        $this->assertSame(0, $limiter->retryAfter($user->email, $ip));
        $this->travel(10)->seconds();
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $limiter->recordFailure('other@example.test', $ip);
        }

        $this->post(route('admin.login.store'), ['email' => 'third@example.test', 'password' => 'incorrecta'])
            ->assertTooManyRequests()->assertHeader('Retry-After', '50');
        $this->assertSame(60, $limiter->retryAfter('other@example.test', $ip));
        $this->assertSame(0, $limiter->retryAfter('other@example.test', '192.0.2.21'));
    }

    public function test_password_recovery_response_is_generic_and_notifies_only_active_users(): void
    {
        Notification::fake();
        $active = $this->adminUser();
        $inactive = User::factory()->create(['is_active' => false]);
        $message = 'Si la dirección corresponde a una cuenta activa, recibirás instrucciones para continuar.';

        $this->post(route('admin.password.email'), ['email' => 'missing@example.test'])
            ->assertSessionHas('status', $message);
        $this->post(route('admin.password.email'), ['email' => $active->email])
            ->assertSessionHas('status', $message);
        $this->post(route('admin.password.email'), ['email' => $inactive->email])
            ->assertSessionHas('status', $message);

        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }

    public function test_valid_password_reset_changes_password_and_invalidates_sessions(): void
    {
        $user = $this->adminUser(['password' => 'Clave-anterior-segura-123!']);
        DB::table('sessions')->insert([
            'id' => 'reset-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => time(),
        ]);
        $token = Password::createToken($user);
        $oldRememberToken = $user->remember_token;
        $oldVersion = $user->session_version;

        $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Clave-nueva-segura-456!',
            'password_confirmation' => 'Clave-nueva-segura-456!',
        ])->assertRedirect(route('admin.login'));

        $user->refresh();
        $this->assertTrue(Hash::check('Clave-nueva-segura-456!', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertSame($oldVersion + 1, $user->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'reset-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('admin_audit_events', [
            'action' => 'user.password_reset',
            'entity_id' => (string) $user->id,
            'actor_id' => null,
        ]);
    }

    public function test_password_reset_rejects_invalid_and_reused_tokens(): void
    {
        $user = $this->adminUser();
        $this->from(route('admin.password.reset', ['token' => 'invalid', 'email' => $user->email]))
            ->post(route('admin.password.update'), [
                'token' => 'invalid',
                'email' => $user->email,
                'password' => 'Clave-nueva-segura-456!',
                'password_confirmation' => 'Clave-nueva-segura-456!',
            ])->assertRedirect()->assertSessionHasErrors('email');

        $token = Password::createToken($user);
        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Clave-nueva-segura-456!',
            'password_confirmation' => 'Clave-nueva-segura-456!',
        ];
        $this->post(route('admin.password.update'), $payload)->assertRedirect(route('admin.login'));
        $this->from(route('admin.password.reset', ['token' => $token, 'email' => $user->email]))
            ->post(route('admin.password.update'), $payload)->assertRedirect()->assertSessionHasErrors('email');
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        $user = $this->adminUser();
        $token = Password::createToken($user);
        Carbon::setTestNow(now()->addMinutes(61));

        try {
            $this->post(route('admin.password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'Clave-nueva-segura-456!',
                'password_confirmation' => 'Clave-nueva-segura-456!',
            ])->assertSessionHasErrors('email');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_login_keys_are_scoped_hmac_digests(): void
    {
        $limiter = app(AdminLoginLimiter::class);
        $email = 'identity@example.test';
        $ip = '192.0.2.22';
        $method = new \ReflectionMethod($limiter, 'identityKey');
        $key = $method->invoke($limiter, $email, $ip);
        $expected = 'admin-login:'.hash_hmac('sha256', "identity-ip\0".$email."\0".$ip, config('app.key'));
        $this->assertTrue(hash_equals($expected, $key));
        $this->assertTrue(! str_contains($key, $email) && ! str_contains($key, $ip));
        $this->assertTrue($key !== $method->invoke($limiter, 'other@example.test', $ip));
        $this->assertTrue($key !== $method->invoke($limiter, $email, '192.0.2.23'));
    }

    public function test_csrf_rejection_does_not_count_as_login_failure(): void
    {
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });

        $email = 'csrf@example.test';
        $payload = ['email' => $email, 'password' => 'incorrecta'];
        $this->post(route('admin.login.store'), $payload)->assertStatus(419);
        $this->assertDatabaseCount('login_attempts', 0);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('admin.login.store'), $payload + ['_token' => session()->token()])
                ->assertSessionHasErrors('email');
        }
        $this->assertDatabaseCount('login_attempts', 5);
    }
}
