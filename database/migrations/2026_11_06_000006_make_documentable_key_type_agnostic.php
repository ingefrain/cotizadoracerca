<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('documentable_id', 255)->change();
        });
    }

    public function down(): void
    {
        $hasNonNumericOwnerKey = false;
        DB::table('documents')->select('id', 'documentable_id')->orderBy('id')->chunk(500, function ($documents) use (&$hasNonNumericOwnerKey): bool {
            foreach ($documents as $document) {
                if (! ctype_digit((string) $document->documentable_id)) {
                    $hasNonNumericOwnerKey = true;

                    return false;
                }
            }

            return true;
        });
        if ($hasNonNumericOwnerKey) {
            throw new RuntimeException('Cannot restore numeric documentable keys while UUID owner keys exist.');
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('documentable_id')->change();
        });
    }
};
