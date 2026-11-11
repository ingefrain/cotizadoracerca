<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\BasePermissions;
use Illuminate\Database\Seeder;

class FormattedDocumentPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $ids = [];
        foreach (['view', 'create', 'update'] as $operation) {
            $key = 'pdf-documents.'.$operation;
            $ids[] = Permission::query()->firstOrCreate(['key' => $key], [
                'description' => BasePermissions::DEFINITIONS[$key],
            ])->id;
        }
        Role::query()->where('key', 'administrator')->first()?->permissions()->syncWithoutDetaching($ids);
    }
}
