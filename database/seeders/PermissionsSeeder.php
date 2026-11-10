<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\BasePermissions;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (BasePermissions::DEFINITIONS as $key => $description) {
            Permission::query()->firstOrCreate(['key' => $key], ['description' => $description]);
        }
    }
}
