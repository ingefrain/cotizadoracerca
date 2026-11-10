<?php

namespace App\Services\Outbox;

use App\Jobs\ProcessOutboxMessage;
use App\Models\OutboxMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OutboxDispatcher
{
    public function __construct(private readonly OutboxTransportResolver $transports) {}

    /** @param array<string, mixed> $payload */
    public function email(
        string $recipient,
        array $payload,
        string $category = 'transactional',
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ): OutboxMessage {
        return $this->dispatch('email', 'generic.email.v1', $recipient, $payload, $category, $idempotencyKey, $correlationId);
    }

    public function telegram(
        string $text,
        ?string $recipient = null,
        string $category = 'transactional',
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ): OutboxMessage {
        $recipient ??= (string) config('services.telegram.default_chat_id', '');

        return $this->dispatch('telegram', 'telegram.text.v1', $recipient, ['text' => $text], $category, $idempotencyKey, $correlationId);
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(
        string $channel,
        string $template,
        string $recipient,
        array $payload,
        string $category = 'transactional',
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ): OutboxMessage {
        $transport = $this->transports->resolve($channel);
        $recipient = $transport->normalizeRecipient($recipient);
        if (! preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/i', $category)) {
            throw new InvalidArgumentException('The notification category is invalid.');
        }
        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 160)) {
            throw new InvalidArgumentException('The idempotency key is invalid.');
        }
        if ($correlationId !== null && (trim($correlationId) === '' || mb_strlen($correlationId) > 100)) {
            throw new InvalidArgumentException('The correlation identifier is invalid.');
        }
        $idempotencyKey = $idempotencyKey === null ? null : trim($idempotencyKey);
        $correlationId = $correlationId === null ? null : trim($correlationId);

        $safePayload = $transport->normalizePayload($template, $payload);
        $recipientHash = hash('sha256', $recipient);
        if ($idempotencyKey !== null) {
            $existing = OutboxMessage::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $this->assertSameRequest($existing, $recipientHash, $channel, $template, $category, $safePayload);
                $this->dispatchAfterCommit($existing->id);

                return $existing;
            }
        }

        try {
            $message = OutboxMessage::query()->create([
                'uuid' => (string) Str::uuid(),
                'category' => $category,
                'channel' => $channel,
                'recipient' => $recipient,
                'recipient_hash' => $recipientHash,
                'template' => $template,
                'payload' => $safePayload,
                'status' => OutboxMessage::PENDING,
                'attempts' => 0,
                'retry_generation' => 0,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $correlationId,
                'available_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($idempotencyKey === null) {
                throw $exception;
            }
            $existing = OutboxMessage::query()->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) {
                throw $exception;
            }
            $this->assertSameRequest($existing, $recipientHash, $channel, $template, $category, $safePayload);
            $this->dispatchAfterCommit($existing->id);

            return $existing;
        }

        $this->dispatchAfterCommit($message->id);

        return $message;
    }

    public function dispatchAfterCommit(int $messageId): void
    {
        $dispatch = function () use ($messageId): void {
            try {
                if (config('queue.default') === 'sync') {
                    return;
                }
                ProcessOutboxMessage::dispatch($messageId);
            } catch (Throwable) {
                Log::warning('outbox.dispatch_failed', ['message_id' => $messageId]);
            }
        };

        try {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($dispatch);
            } else {
                $dispatch();
            }
        } catch (Throwable) {
            Log::warning('outbox.after_commit_registration_failed', ['message_id' => $messageId]);
        }
    }

    /** @param array<string, string> $payload */
    private function assertSameRequest(OutboxMessage $existing, string $recipientHash, string $channel, string $template, string $category, array $payload): void
    {
        if ($existing->recipient_hash !== $recipientHash
            || $existing->channel !== $channel
            || $existing->template !== $template
            || $existing->category !== $category
            || $existing->payload !== $payload) {
            throw new RuntimeException('An idempotency key cannot be reused for a different notification.');
        }
    }
}
