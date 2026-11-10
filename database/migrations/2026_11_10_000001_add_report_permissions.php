<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'reports.view' => 'Consultar reportes administrativos',
        'reports.export' => 'Exportar reportes administrativos',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $key => $description) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = DB::table('roles')->where('key', 'administrator')->value('id');
        if ($roleId !== null) {
            foreach ($this->permissionIds() as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = $this->permissionIds();
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function permissionIds(): array
    {
        return DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id')->all();
    }
};
