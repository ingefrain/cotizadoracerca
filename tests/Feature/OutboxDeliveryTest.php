<?php

namespace Tests\Feature;

use App\Contracts\OutboxEmailSender;
use App\Mail\TransactionalEmail;
use App\Models\OutboxAttempt;
use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxProcessor;
use App\Services\Outbox\OutboxTransportResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\FixedOutboxTransport;
use Tests\TestCase;

class OutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_creation_rolls_back_with_its_business_transaction_and_does_not_send_before_commit(): void
    {
        config(['queue.default' => 'database']);
        Mail::fake();
        Queue::fake();
        $dispatcher = app(OutboxDispatcher::class);
        DB::beginTransaction();
        $message = $dispatcher->email('persona@example.test', $this->payload());

        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('outbox_messages', ['id' => $message->id, 'status' => OutboxMessage::PENDING]);

        DB::rollBack();

        $this->assertDatabaseMissing('outbox_messages', ['id' => $message->id]);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_idempotency_returns_the_original_message_and_rejects_a_different_payload(): void
    {
        $dispatcher = app(OutboxDispatcher::class);
        $first = $dispatcher->email('persona@example.test', $this->payload(), idempotencyKey: 'operation-123');
        $same = $dispatcher->email('PERSONA@example.test', $this->payload(), idempotencyKey: 'operation-123');

        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('outbox_messages', 1);

        try {
            $dispatcher->email('persona@example.test', $this->payload('Otro contenido'), idempotencyKey: 'operation-123');
            $this->fail('A changed payload must not reuse an idempotency key.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('outbox_messages', 1);
        }
    }

    public function test_successful_delivery_is_recorded_once_and_a_second_execution_is_a_no_op(): void
    {
        Mail::fake();
        $message = app(OutboxDispatcher::class)->email('persona@example.test', $this->payload());
        $processor = app(OutboxProcessor::class);

        $this->assertTrue($processor->process($message->id));
        $this->assertFalse($processor->process($message->id));

        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame($this->payload(), $message->fresh()->payload);
        $this->assertSame(1, $message->attemptsHistory()->count());
        $this->assertSame('accepted', $message->attemptsHistory()->first()->result);
        Mail::assertSent(TransactionalEmail::class, fn (TransactionalEmail $mail): bool => $mail->hasTo('persona@example.test'));
    }

    public function test_transient_transport_failure_uses_backoff_and_never_persists_exception_secrets(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->instance(OutboxEmailSender::class, new class implements OutboxEmailSender
        {
            public function send(string $recipient, array $payload): void
            {
                throw new RuntimeException('smtp password=very-private; token=private-token');
            }
        });
        $message = app(OutboxDispatcher::class)->email('persona@example.test', $this->payload());

        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));

        $message->refresh();
        $attempt = $message->attemptsHistory()->firstOrFail();
        $this->assertSame(OutboxMessage::PENDING, $message->status);
        $this->assertSame('2026-11-05 12:01:00', $message->available_at->format('Y-m-d H:i:s'));
        $this->assertSame('delivery', $message->last_error_type);
        $this->assertStringNotContainsString('very-private', $message->last_error_message);
        $this->assertStringNotContainsString('private-token', $attempt->error_message);
        $this->assertStringNotContainsString('very-private', json_encode($message->payload));
        Carbon::setTestNow();
    }

    public function test_retries_exhaust_and_leave_a_terminal_failed_record_with_complete_attempt_history(): void
    {
        $this->instance(OutboxEmailSender::class, new class implements OutboxEmailSender
        {
            public function send(string $recipient, array $payload): void
            {
                throw new RuntimeException('transport unavailable');
            }
        });
        $message = app(OutboxDispatcher::class)->email('persona@example.test', $this->payload());
        $clock = now();

        for ($cycleAttempt = 1; $cycleAttempt <= OutboxProcessor::MAX_ATTEMPTS; $cycleAttempt++) {
            Carbon::setTestNow($clock);
            $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
            $message->refresh();
            if ($message->status === OutboxMessage::PENDING) {
                $clock = $message->available_at;
            }
        }

        $this->assertSame(OutboxMessage::FAILED, $message->fresh()->status);
        $this->assertSame(OutboxProcessor::MAX_ATTEMPTS, $message->attemptsHistory()->count());
        Carbon::setTestNow();
    }

    public function test_expired_processing_lease_is_recovered_and_previous_attempt_marked_abandoned(): void
    {
        Mail::fake();
        $message = app(OutboxDispatcher::class)->email('persona@example.test', $this->payload());
        $oldToken = (string) Str::uuid();
        $message->forceFill([
            'status' => OutboxMessage::PROCESSING,
            'attempts' => 1,
            'lease_token' => $oldToken,
            'lease_expires_at' => now()->subMinute(),
        ])->save();
        OutboxAttempt::query()->create([
            'outbox_message_id' => $message->id,
            'attempt_number' => 1,
            'lease_token' => $oldToken,
            'started_at' => now()->subMinutes(6),
        ]);

        $this->assertTrue(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(['accepted', 'abandoned'], $message->attemptsHistory()->pluck('result')->all());
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
    }

    public function test_payload_rejects_unknown_sensitive_fields_before_storing_them(): void
    {
        try {
            app(OutboxDispatcher::class)->email('persona@example.test', $this->payload() + ['password' => 'must-not-persist']);
            $this->fail('Sensitive or unknown payload keys must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    public function test_permanent_configuration_errors_fail_without_retrying(): void
    {
        $this->instance(OutboxEmailSender::class, new class implements OutboxEmailSender
        {
            public function send(string $recipient, array $payload): void
            {
                throw new InvalidArgumentException('smtp user=private; password=private');
            }
        });
        $message = app(OutboxDispatcher::class)->email('persona@example.test', $this->payload());

        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::FAILED, $message->fresh()->status);
        $this->assertSame(1, $message->attemptsHistory()->count());
        $this->assertSame('configuration', $message->fresh()->last_error_type);
        $this->assertStringNotContainsString('private', $message->fresh()->last_error_message);
    }

    public function test_uncertain_transport_result_is_terminal_and_is_not_retried_automatically(): void
    {
        config(['notifications.transports.test' => ['enabled' => true, 'class' => FixedOutboxTransport::class]]);
        FixedOutboxTransport::$result = OutboxTransportResult::uncertain('test.unknown', 'delivery', 'Provider acceptance could not be confirmed.');
        $message = app(OutboxDispatcher::class)->dispatch('test', 'generic.test.v1', 'recipient', ['message' => 'Hello']);

        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::UNCERTAIN, $message->fresh()->status);
        $this->assertSame('uncertain', $message->attemptsHistory()->firstOrFail()->result);
        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(1, $message->attemptsHistory()->count());
        FixedOutboxTransport::$result = null;
    }

    public function test_retryable_result_can_schedule_a_future_time_without_sleeping(): void
    {
        config(['notifications.transports.test' => ['enabled' => true, 'class' => FixedOutboxTransport::class]]);
        $retryAt = now()->addMinutes(7);
        FixedOutboxTransport::$result = OutboxTransportResult::retryable('test.limited', 'rate_limit', 'Provider rate limit reached.', $retryAt);
        $message = app(OutboxDispatcher::class)->dispatch('test', 'generic.test.v1', 'recipient', ['message' => 'Hello']);

        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame($retryAt->timestamp, $message->fresh()->available_at->timestamp);
        $this->assertSame('retryable_failure', $message->attemptsHistory()->firstOrFail()->result);
        FixedOutboxTransport::$result = null;
    }

    public function test_sync_outbox_command_sends_ready_messages_through_the_same_processor(): void
    {
        Mail::fake();
        $message = app(OutboxDispatcher::class)->email('persona@example.test', $this->payload());

        $this->artisan('outbox:process', ['--sync' => true])
            ->expectsOutputToContain('Mensajes procesados o despachados: 1')
            ->assertExitCode(0);

        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        Mail::assertSent(TransactionalEmail::class);
    }

    public function test_test_email_command_creates_a_generic_message_without_exposing_the_recipient(): void
    {
        $this->artisan('outbox:test-email', ['recipient' => 'admin@example.test'])
            ->expectsOutputToContain('Mensaje creado:')
            ->assertExitCode(0);

        $this->assertDatabaseHas('outbox_messages', [
            'recipient_hash' => hash('sha256', 'admin@example.test'),
            'category' => 'administrative.test',
            'template' => 'generic.email.v1',
            'status' => OutboxMessage::PENDING,
        ]);
    }

    /** @return array<string, string> */
    private function payload(string $message = 'Mensaje genérico de prueba.'): array
    {
        return ['subject' => 'Aviso de prueba', 'heading' => 'Cotizador Acerca', 'message' => $message];
    }
}
