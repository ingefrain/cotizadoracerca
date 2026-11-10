<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'users.impersonate')->value('id');
        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => 'users.impersonate',
                'description' => 'Iniciar sesión temporal como otro usuario',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $administratorId = DB::table('roles')->where('key', 'administrator')->value('id');
        if ($administratorId !== null && ! DB::table('permission_role')
            ->where('permission_id', $permissionId)->where('role_id', $administratorId)->exists()) {
            DB::table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id' => $administratorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'users.impersonate')->value('id');
        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
