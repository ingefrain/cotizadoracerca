<?php

namespace App\Contracts;

interface OutboxEmailSender
{
    /** @param array<string, string> $payload */
    public function send(string $recipient, array $payload): void;
}
