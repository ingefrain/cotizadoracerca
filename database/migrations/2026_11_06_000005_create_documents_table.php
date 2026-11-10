<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'documents.view' => 'Consultar metadata de documentos permitidos',
        'documents.upload' => 'Subir documentos',
        'documents.download' => 'Descargar documentos autorizados',
        'documents.update' => 'Actualizar metadata y reemplazar documentos',
        'documents.delete' => 'Marcar documentos para eliminación',
    ];

    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->morphs('documentable');
            $table->string('category', 100)->index();
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('disk', 100);
            $table->string('path', 255);
            $table->string('mime_type', 255)->index();
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64)->index();
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['disk', 'path']);
            $table->index(['category', 'created_at']);
            $table->index(['deleted_at', 'created_at']);
        });

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
        Schema::dropIfExists('documents');
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
};
