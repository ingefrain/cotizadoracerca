<?php

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'organizations.view' => 'Consultar organizaciones',
        'organizations.create' => 'Crear organizaciones',
        'organizations.update' => 'Editar organizaciones',
        'organizations.disable' => 'Activar o desactivar organizaciones',
        'locations.view' => 'Consultar sedes',
        'locations.create' => 'Crear sedes',
        'locations.update' => 'Editar sedes',
        'locations.disable' => 'Activar o desactivar sedes',
    ];

    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 180);
            $table->string('legal_name', 180)->nullable();
            $table->string('slug', 140)->unique();
            $table->string('email', 254)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('tax_id', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('code', 100)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address_line_1', 180)->nullable();
            $table->string('address_line_2', 180)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'name']);
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
        if (Schema::hasTable('documents')) {
            $organizationType = (new Organization)->getMorphClass();
            $locationType = (new Location)->getMorphClass();
            if (DB::table('documents')->whereIn('documentable_type', [$organizationType, $locationType])->exists()) {
                throw new RuntimeException('No se puede revertir organizations/locations mientras existan documentos asociados.');
            }
        }

        Schema::dropIfExists('locations');
        Schema::dropIfExists('organizations');

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
};
