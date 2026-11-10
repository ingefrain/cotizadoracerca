<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateBaseAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_the_first_administrator_without_persisting_a_fixture(): void
    {
        $this->artisan('base:admin')
            ->expectsQuestion('Nombre', 'Administradora Inicial')
            ->expectsQuestion('Correo', 'inicio@example.test')
            ->expectsQuestion('Contraseña (mínimo 8 caracteres)', 'Clave-segura-inicial-123!')
            ->expectsQuestion('Confirma la contraseña', 'Clave-segura-inicial-123!')
            ->expectsOutputToContain('Cuenta administrativa creada.')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'inicio@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Clave-segura-inicial-123!', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertSame(['administrator'], $user->roles()->pluck('key')->all());
        $this->assertTrue($user->can('permissions.assign'));
        $this->assertSame(1, Role::query()->where('key', 'administrator')->count());
    }

    public function test_command_reads_credentials_from_a_file_without_printing_them(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'base-admin-');
        file_put_contents($path, implode(PHP_EOL, [
            'name: Archivo Inicial',
            'email: archivo@example.test',
            'password: Clave-archivo-segura-123!',
        ]));

        try {
            $this->artisan('base:admin', ['--from-file' => $path])
                ->expectsOutputToContain('Cuenta administrativa creada.')
                ->assertExitCode(0);

            $user = User::query()->where('email', 'archivo@example.test')->firstOrFail();
            $this->assertTrue(Hash::check('Clave-archivo-segura-123!', $user->password));
            $this->assertTrue($user->can('permissions.assign'));
        } finally {
            @unlink($path);
        }
    }

    public function test_command_accepts_the_shared_eight_character_password_minimum(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'base-admin-short-policy-');
        file_put_contents($path, implode(PHP_EOL, [
            'name: Admin Inicial',
            'email: minimo@example.test',
            'password: Abcd123!',
        ]));

        try {
            $this->artisan('base:admin', ['--from-file' => $path])->assertExitCode(0);
            $this->assertTrue(User::query()->where('email', 'minimo@example.test')->exists());
        } finally {
            @unlink($path);
        }
    }
}
