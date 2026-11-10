<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('category', 80)->index();
            $table->string('channel', 32)->index();
            $table->string('recipient', 320);
            $table->char('recipient_hash', 64)->index();
            $table->string('template', 100);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('retry_generation')->default(0);
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->string('correlation_id', 100)->nullable()->index();
            $table->timestamp('available_at')->index();
            $table->uuid('lease_token')->nullable()->index();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->string('last_error_type', 80)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id'], 'outbox_ready_idx');
            $table->index(['status', 'lease_expires_at', 'id'], 'outbox_lease_idx');
        });

        Schema::create('outbox_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_message_id')->constrained('outbox_messages')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->uuid('lease_token');
            $table->string('result', 20)->nullable();
            $table->string('error_type', 80)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->unique(['outbox_message_id', 'attempt_number'], 'outbox_attempt_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_attempts');
        Schema::dropIfExists('outbox_messages');
    }
};
