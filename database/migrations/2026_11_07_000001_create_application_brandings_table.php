<?php

use App\Models\ApplicationBranding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_brandings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('visible_name', 120)->nullable();
            $table->timestamps();
        });
        DB::table('application_brandings')->insert(['id' => 1, 'visible_name' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore([
            'key' => 'branding.manage', 'description' => 'Administrar nombre y logotipo de la aplicación',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('key', 'administrator')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'branding.manage')->value('id');
        if ($roleId && $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $type = (new ApplicationBranding)->getMorphClass();
        if (Schema::hasTable('documents') && DB::table('documents')->where('documentable_type', $type)->where('documentable_id', '1')->exists()) {
            throw new RuntimeException('No se puede quitar application_brandings mientras existan documentos asociados.');
        }
        Schema::dropIfExists('application_brandings');
        $id = DB::table('permissions')->where('key', 'branding.manage')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
