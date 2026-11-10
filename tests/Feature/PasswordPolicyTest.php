<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_shared_eight_character_policy_applies_to_user_creation_edit_profile_and_reset(): void
    {
        $admin = $this->adminUser(['password' => 'Previous-Password-123!']);
        $this->actingAsAdmin($admin);

        $this->post(route('admin.users.store'), [
            'name' => 'Eight Character User',
            'email' => 'eight@example.test',
            'password' => 'Abcd123!',
            'password_confirmation' => 'Abcd123!',
        ])->assertRedirect();
        $created = User::query()->where('email', 'eight@example.test')->firstOrFail();

        $this->put(route('admin.users.update', $created), [
            'name' => $created->name,
            'email' => $created->email,
            'password' => 'Efgh456!',
            'password_confirmation' => 'Efgh456!',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('Efgh456!', $created->fresh()->password));

        $this->put(route('admin.profile.password'), [
            'password' => 'Gijk789!',
            'password_confirmation' => 'Gijk789!',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('Gijk789!', $admin->fresh()->password));

        auth()->logout();
        $resetUser = User::factory()->create();
        $token = Password::createToken($resetUser);
        $this->post(route('admin.password.update'), [
            'email' => $resetUser->email,
            'token' => $token,
            'password' => 'Lmno321!',
            'password_confirmation' => 'Lmno321!',
        ])->assertRedirect(route('admin.login'));
        $this->assertTrue(Hash::check('Lmno321!', $resetUser->fresh()->password));
    }

    public function test_profile_route_rejects_invalid_passwords_even_without_frontend_validation(): void
    {
        $admin = $this->adminUser();
        $originalPassword = $admin->password;
        $this->actingAsAdmin($admin);

        foreach ([
            ['Abc123!', 'Abc123!'],
            ['abcdefgh', 'abcdefgh'],
            ['ABCDEFG1', 'ABCDEFG1'],
            ['Abcdefgh', 'Abcdefgh'],
            ['Abcd123!', 'Abcd123?'],
            [str_repeat('A', 36).str_repeat('a', 36).'1', str_repeat('A', 36).str_repeat('a', 36).'1'],
        ] as [$password, $confirmation]) {
            $this->put(route('admin.profile.password'), [
                'password' => $password,
                'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password');
        }

        $this->assertSame($originalPassword, $admin->fresh()->password);
    }

    public function test_administrative_user_creation_enforces_confirmation_and_72_character_maximum(): void
    {
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin);

        foreach ([
            ['Valid-Password-123!', 'Different-Password-123!'],
            [str_repeat('A', 36).str_repeat('a', 36).'1', str_repeat('A', 36).str_repeat('a', 36).'1'],
        ] as [$password, $confirmation]) {
            $this->post(route('admin.users.store'), [
                'name' => 'Rejected User',
                'email' => 'rejected-'.strlen($password).'-'.substr(sha1($password.$confirmation), 0, 6).'@example.test',
                'password' => $password,
                'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password');
        }

        $this->assertSame(0, User::query()->where('name', 'Rejected User')->count());
    }

    public function test_profile_renders_dropzone_fallback_and_live_password_requirements(): void
    {
        $user = $this->adminUser(['name' => 'Efraín González']);
        $this->actingAsAdmin($user)->get(route('admin.profile.edit'))->assertOk()
            ->assertSee('Arrastra una foto aquí o selecciónala.')
            ->assertSee('data-file-dropzone', false)
            ->assertSee('EG')
            ->assertSee('data-password-policy', false)
            ->assertSee('data-modal-open="password-change-dialog"', false)
            ->assertSee('data-profile-modal', false)
            ->assertSee('Mínimo 8 caracteres')
            ->assertSee('Una letra mayúscula')
            ->assertSee('Un número')
            ->assertSee('Las contraseñas coinciden')
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('Hasta 72 caracteres')
            ->assertSee('data-password-limit role="alert" aria-live="assertive" hidden', false)
            ->assertDontSee('data-rule="maximum"', false)
            ->assertSee('class="sr-only" data-rule-status', false);
    }

    public function test_profile_password_errors_reopen_modal_and_remain_server_rendered(): void
    {
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin)->put(route('admin.profile.password'), [
            'password' => 'Abc123!',
            'password_confirmation' => 'Abc123!',
        ])->assertSessionHasErrors('password');

        $this->get(route('admin.profile.edit'))->assertOk()
            ->assertSee('data-open-on-load', false)
            ->assertSee('id="password-error"', false);
    }
}
