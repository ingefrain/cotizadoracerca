<?php

namespace App\Services\Outbox;

use App\Models\OutboxAttempt;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class OutboxProcessor
{
    public const MAX_ATTEMPTS = 6;

    public const LEASE_SECONDS = 300;

    private const RETRY_DELAYS = [60, 300, 900, 3600, 21600];

    public function __construct(private readonly OutboxTransportResolver $transports) {}

    public function process(int $messageId): bool
    {
        $claim = $this->claim($messageId);
        if ($claim === null) {
            return false;
        }

        /** @var OutboxMessage $message */
        $message = $claim['message'];
        /** @var OutboxAttempt $attempt */
        $attempt = $claim['attempt'];
        $leaseToken = $claim['lease_token'];

        try {
            $result = $this->transports->resolve($message->channel)->send($message);
        } catch (InvalidArgumentException) {
            $result = OutboxTransportResult::permanent('transport.invalid', 'configuration', 'Notification transport configuration or message is invalid; details redacted.');
        } catch (Throwable) {
            $result = OutboxTransportResult::retryable('transport.internal', 'delivery', 'Notification transport failed; details redacted.');
        }

        if ($result->status === OutboxTransportResult::ACCEPTED) {
            return $this->finishSent($message->id, $attempt->id, $leaseToken, $result->providerReference);
        }
        if ($result->status === OutboxTransportResult::UNCERTAIN) {
            $this->finishUncertain($message->id, $attempt->id, $leaseToken, $result);
            Log::warning('outbox.delivery_uncertain', ['message_id' => $message->id, 'attempt_number' => $attempt->attempt_number]);

            return false;
        }

        $terminal = $result->status === OutboxTransportResult::PERMANENT_FAILURE || $message->attempts >= self::MAX_ATTEMPTS;
        $delay = self::RETRY_DELAYS[min(max($message->attempts - 1, 0), count(self::RETRY_DELAYS) - 1)];
        $retryAt = $result->retryAt && $result->retryAt->isFuture() ? $result->retryAt : now()->addSeconds($delay);
        $this->finishFailed($message->id, $attempt->id, $leaseToken, $terminal, $result, $terminal ? null : $retryAt);
        Log::warning('outbox.delivery_failed', ['message_id' => $message->id, 'attempt_number' => $attempt->attempt_number, 'error_type' => $result->errorType]);

        return false;
    }

    /** @return array{message: OutboxMessage, attempt: OutboxAttempt, lease_token: string}|null */
    private function claim(int $messageId): ?array
    {
        $claim = DB::transaction(function () use ($messageId): ?array {
            $now = now();
            $leaseToken = (string) Str::uuid();
            $updated = OutboxMessage::query()->whereKey($messageId)
                ->where(function ($query) use ($now): void {
                    $query->where(function ($pending) use ($now): void {
                        $pending->where('status', OutboxMessage::PENDING)->where('available_at', '<=', $now);
                    })->orWhere(function ($expired) use ($now): void {
                        $expired->where('status', OutboxMessage::PROCESSING)->where('lease_expires_at', '<=', $now);
                    });
                })
                ->update([
                    'status' => OutboxMessage::PROCESSING,
                    'attempts' => DB::raw('attempts + 1'),
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                    'processed_at' => null,
                ]);

            if ($updated !== 1) {
                return null;
            }

            OutboxAttempt::query()->where('outbox_message_id', $messageId)->whereNull('finished_at')
                ->update([
                    'result' => 'abandoned',
                    'error_type' => 'lease_expired',
                    'error_message' => 'Previous processing lease expired; details unavailable.',
                    'finished_at' => $now,
                ]);

            $attemptNumber = (int) OutboxAttempt::query()
                ->where('outbox_message_id', $messageId)->max('attempt_number') + 1;
            $attempt = OutboxAttempt::query()->create([
                'outbox_message_id' => $messageId,
                'attempt_number' => $attemptNumber,
                'lease_token' => $leaseToken,
                'started_at' => $now,
            ]);

            return ['attempt_id' => $attempt->id, 'lease_token' => $leaseToken];
        });

        if ($claim === null) {
            return null;
        }

        return [
            'message' => OutboxMessage::query()->findOrFail($messageId),
            'attempt' => OutboxAttempt::query()->findOrFail($claim['attempt_id']),
            'lease_token' => $claim['lease_token'],
        ];
    }

    private function finishSent(int $messageId, int $attemptId, string $leaseToken, ?string $providerReference): bool
    {
        return DB::transaction(function () use ($messageId, $attemptId, $leaseToken, $providerReference): bool {
            $now = now();
            $updated = OutboxMessage::query()->whereKey($messageId)->where('status', OutboxMessage::PROCESSING)->where('lease_token', $leaseToken)
                ->update([
                    'status' => OutboxMessage::SENT,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'processed_at' => $now,
                    'sent_at' => $now,
                    'last_error_type' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            if ($updated !== 1) {
                return false;
            }
            OutboxAttempt::query()->whereKey($attemptId)->where('lease_token', $leaseToken)->whereNull('finished_at')
                ->update(['result' => OutboxTransportResult::ACCEPTED, 'provider_reference' => $providerReference, 'finished_at' => $now]);

            return true;
        });
    }

    private function finishFailed(
        int $messageId,
        int $attemptId,
        string $leaseToken,
        bool $terminal,
        OutboxTransportResult $result,
        mixed $availableAt,
    ): bool {
        return DB::transaction(function () use ($messageId, $attemptId, $leaseToken, $terminal, $result, $availableAt): bool {
            $now = now();
            $updated = OutboxMessage::query()->whereKey($messageId)->where('status', OutboxMessage::PROCESSING)->where('lease_token', $leaseToken)
                ->update([
                    'status' => $terminal ? OutboxMessage::FAILED : OutboxMessage::PENDING,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'available_at' => $availableAt ?? $now,
                    'processed_at' => $terminal ? $now : null,
                    'last_error_type' => $result->errorType,
                    'last_error_code' => $result->code,
                    'last_error_message' => $result->errorMessage,
                ]);
            if ($updated !== 1) {
                return false;
            }
            OutboxAttempt::query()->whereKey($attemptId)->where('lease_token', $leaseToken)->whereNull('finished_at')
                ->update([
                    'result' => $result->status,
                    'provider_reference' => $result->providerReference,
                    'error_type' => $result->errorType,
                    'error_code' => $result->code,
                    'error_message' => $result->errorMessage,
                    'finished_at' => $now,
                ]);

            return true;
        });
    }

    private function finishUncertain(int $messageId, int $attemptId, string $leaseToken, OutboxTransportResult $result): bool
    {
        return DB::transaction(function () use ($messageId, $attemptId, $leaseToken, $result): bool {
            $now = now();
            $updated = OutboxMessage::query()->whereKey($messageId)->where('status', OutboxMessage::PROCESSING)->where('lease_token', $leaseToken)
                ->update([
                    'status' => OutboxMessage::UNCERTAIN,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'processed_at' => $now,
                    'last_error_type' => $result->errorType,
                    'last_error_code' => $result->code,
                    'last_error_message' => $result->errorMessage,
                ]);
            if ($updated !== 1) {
                return false;
            }
            OutboxAttempt::query()->whereKey($attemptId)->where('lease_token', $leaseToken)->whereNull('finished_at')
                ->update([
                    'result' => OutboxTransportResult::UNCERTAIN,
                    'provider_reference' => $result->providerReference,
                    'error_type' => $result->errorType,
                    'error_code' => $result->code,
                    'error_message' => $result->errorMessage,
                    'finished_at' => $now,
                ]);

            return true;
        });
    }
}
