<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'notifications.view' => 'Consultar mensajes transaccionales y sus intentos',
        'notifications.retry' => 'Reintentar mensajes transaccionales fallidos',
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
        if ($roleId) {
            $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
};
