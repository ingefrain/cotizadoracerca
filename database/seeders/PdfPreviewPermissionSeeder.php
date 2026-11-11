<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\BasePermissions;
use Illuminate\Database\Seeder;

/** Enable only this capability on existing installations; leave all other grants alone. */
class PdfPreviewPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::query()->firstOrCreate(['key' => 'pdf.preview'], [
            'description' => BasePermissions::DEFINITIONS['pdf.preview'],
        ]);
        Role::query()->where('key', 'administrator')->first()?->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
