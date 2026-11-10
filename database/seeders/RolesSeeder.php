<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $administrator = Role::query()->firstOrCreate(
            ['key' => 'administrator'],
            ['name' => 'Administrador', 'description' => 'Acceso administrativo completo.', 'is_active' => true],
        );

        $administrator->permissions()->syncWithoutDetaching(Permission::query()->pluck('id')->all());

        Role::query()->firstOrCreate(
            ['key' => 'operator'],
            ['name' => 'Operador', 'description' => 'Rol base al que se asignan capacidades necesarias.', 'is_active' => true],
        );
    }
}
