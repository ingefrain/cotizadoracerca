<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_attempts', function (Blueprint $table): void {
            $table->string('provider_reference', 100)->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_attempts', function (Blueprint $table): void {
            $table->dropColumn('provider_reference');
        });
    }
};
