<?php

namespace Tests\Support;

use App\Contracts\OutboxTransport;
use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxTransportResult;

final class FixedOutboxTransport implements OutboxTransport
{
    public static ?OutboxTransportResult $result = null;

    public function normalizePayload(string $template, array $payload): array
    {
        return $payload;
    }

    public function normalizeRecipient(string $recipient): string
    {
        return trim($recipient);
    }

    public function send(OutboxMessage $message): OutboxTransportResult
    {
        return self::$result ?? OutboxTransportResult::accepted();
    }

    public function maskRecipient(string $recipient): string
    {
        return '***';
    }
}
