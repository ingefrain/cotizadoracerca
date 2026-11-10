<?php

namespace App\Contracts;

use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxTransportResult;

interface OutboxTransport
{
    /** @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function normalizePayload(string $template, array $payload): array;

    public function normalizeRecipient(string $recipient): string;

    public function send(OutboxMessage $message): OutboxTransportResult;

    public function maskRecipient(string $recipient): string;
}
