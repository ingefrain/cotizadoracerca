<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'imports.view' => 'Consultar importaciones propias',
        'imports.create' => 'Cargar y validar archivos de importación',
        'imports.apply' => 'Aplicar importaciones validadas',
    ];

    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 32);
            $table->string('original_name', 180);
            $table->string('disk', 32);
            $table->string('path', 180)->nullable();
            $table->char('checksum_sha256', 64);
            $table->char('delimiter', 1)->nullable();
            $table->string('status', 20);
            foreach (['total_rows', 'valid_rows', 'invalid_rows', 'applied_rows'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('effective_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['created_by', 'effective_user_id', 'id']);
        });
        Schema::create('import_row_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('field', 64)->nullable();
            $table->string('error_code', 40);
            $table->string('message', 255);
            $table->index(['import_run_id', 'row_number']);
        });

        foreach (self::PERMISSIONS as $key => $description) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, 'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $role = DB::table('roles')->where('key', 'administrator')->value('id');
        if ($role !== null) {
            foreach ($this->permissionIds() as $id) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $id, 'role_id' => $role]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_errors');
        Schema::dropIfExists('import_runs');
        $ids = $this->permissionIds();
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function permissionIds(): array
    {
        return DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id')->all();
    }
};
