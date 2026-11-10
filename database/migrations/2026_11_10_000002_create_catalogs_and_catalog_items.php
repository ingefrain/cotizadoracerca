<?php

use App\Models\Catalog;
use App\Models\CatalogItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'catalogs.view' => 'Consultar catálogos',
        'catalogs.create' => 'Crear catálogos',
        'catalogs.update' => 'Editar catálogos',
        'catalogs.disable' => 'Cambiar estado de catálogos',
        'catalog_items.view' => 'Consultar elementos de catálogo',
        'catalog_items.create' => 'Crear elementos de catálogo',
        'catalog_items.update' => 'Editar elementos de catálogo',
        'catalog_items.disable' => 'Cambiar estado de elementos de catálogo',
    ];

    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('slug', 140)->unique();
            $table->text('description_markdown')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('catalog_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('slug', 140);
            $table->string('code', 100)->nullable();
            $table->text('description_markdown')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['catalog_id', 'slug']);
            $table->unique(['catalog_id', 'code']);
            $table->index(['catalog_id', 'sort_order', 'name']);
        });

        $now = now();
        foreach (self::PERMISSIONS as $key => $description) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, 'description' => $description, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $roleId = DB::table('roles')->where('key', 'administrator')->value('id');
        if ($roleId !== null) {
            foreach ($this->permissionIds() as $id) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documents') && DB::table('documents')->whereIn('documentable_type', [
            (new Catalog)->getMorphClass(),
            (new CatalogItem)->getMorphClass(),
        ])->exists()) {
            throw new RuntimeException('No se puede revertir Catálogos mientras existan documentos asociados.');
        }

        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalogs');
        $ids = $this->permissionIds();
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function permissionIds(): array
    {
        return DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id')->all();
    }
};
