<?php

namespace Tests\Feature;

use App\Jobs\ProcessOutboxMessage;
use App\Services\Outbox\OutboxDispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OutboxAfterCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('category', 80);
            $table->string('channel', 32);
            $table->string('recipient', 320);
            $table->char('recipient_hash', 64);
            $table->string('template', 100);
            $table->json('payload');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('retry_generation')->default(0);
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->string('correlation_id', 100)->nullable();
            $table->timestamp('available_at');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error_type', 80)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 255)->nullable();
            $table->timestamps();
        });
    }

    public function test_job_is_dispatched_only_after_transaction_commit_and_contains_only_the_message_id(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $dispatcher = app(OutboxDispatcher::class);
        $message = null;

        DB::transaction(function () use ($dispatcher, &$message): void {
            $message = $dispatcher->email('persona@example.test', [
                'subject' => 'Confirmación',
                'message' => 'Una operación genérica fue confirmada.',
            ]);
            Queue::assertNothingPushed();
        });

        Queue::assertPushed(ProcessOutboxMessage::class, fn (ProcessOutboxMessage $job): bool => $job->messageId === $message->id);
    }
}
