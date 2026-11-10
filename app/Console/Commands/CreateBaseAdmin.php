<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminAuditLogger;
use App\Support\BasePermissions;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateBaseAdmin extends Command
{
    protected $signature = 'base:admin {--from-file= : Leer name, email y password de un archivo local}';

    protected $description = 'Crea interactivamente la primera cuenta administrativa de Cotizador Acerca';

    public function handle(AdminAuditLogger $audit): int
    {
        $file = $this->option('from-file');
        if ($file) {
            try {
                $input = $this->readInputFile((string) $file);
            } catch (ValidationException) {
                $this->components->error('El archivo debe contener una sola vez los campos name, email y password.');

                return self::FAILURE;
            }
            $name = $input['name'];
            $email = mb_strtolower($input['email']);
            $password = $input['password'];
            $confirmation = $password;
        } else {
            $name = trim((string) $this->ask('Nombre'));
            $email = mb_strtolower(trim((string) $this->ask('Correo')));
            $password = (string) $this->secret('Contraseña (mínimo '.PasswordPolicy::minimumLength().' caracteres)');
            $confirmation = (string) $this->secret('Confirma la contraseña');
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => PasswordPolicy::rules(),
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }
        $user = DB::transaction(function () use ($name, $email, $password, $audit): User {
            foreach (BasePermissions::DEFINITIONS as $key => $description) {
                Permission::query()->firstOrCreate(['key' => $key], ['description' => $description]);
            }

            $role = Role::query()->firstOrCreate(
                ['key' => 'administrator'],
                ['name' => 'Administrador', 'description' => 'Acceso administrativo completo.', 'is_active' => true],
            );
            $role->forceFill(['is_active' => true])->save();
            $role->permissions()->sync(Permission::query()->pluck('id')->all());

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
            ]);
            $user->roles()->attach($role);
            $audit->record('user.created', 'user', $user->id, null, [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => true,
                'roles' => ['administrator'],
            ], ['source' => 'base:admin']);

            return $user;
        });

        $this->components->info('Cuenta administrativa creada.');

        return self::SUCCESS;
    }

    /** @return array{name: string, email: string, password: string} */
    private function readInputFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['file' => 'Archivo no disponible.']);
        }

        $fields = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = ltrim($line, "\xEF\xBB\xBF");
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*(name|email|password)\s*[:=]\s*(.*)$/i', $line, $matches)) {
                continue;
            }

            $key = strtolower($matches[1]);
            if (array_key_exists($key, $fields)) {
                throw ValidationException::withMessages(['file' => 'Campo duplicado.']);
            }
            $fields[$key] = $key === 'password' ? $matches[2] : trim($matches[2]);
        }

        foreach (['name', 'email', 'password'] as $key) {
            if (! array_key_exists($key, $fields) || $fields[$key] === '') {
                throw ValidationException::withMessages(['file' => 'Falta un campo.']);
            }
        }

        return $fields;
    }
}
