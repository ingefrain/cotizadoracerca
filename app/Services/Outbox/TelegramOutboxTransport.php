<?php

namespace App\Services\Outbox;

use App\Contracts\OutboxTransport;
use App\Models\OutboxMessage;
use App\Services\TelegramClient;
use InvalidArgumentException;

final class TelegramOutboxTransport implements OutboxTransport
{
    private const TEMPLATE = 'telegram.text.v1';

    private const MAX_TEXT_LENGTH = 4096;

    public function normalizePayload(string $template, array $payload): array
    {
        if ($template !== self::TEMPLATE || array_diff(array_keys($payload), ['text']) !== []) {
            throw new InvalidArgumentException('Unsupported Telegram payload version or fields.');
        }

        $text = $payload['text'] ?? null;
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || trim($text) === '') {
            throw new InvalidArgumentException('Telegram text must be non-empty UTF-8 text.');
        }

        $text = trim($text);
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException('Telegram text exceeds the allowed length.');
        }

        return ['text' => $text];
    }

    public function __construct(private readonly TelegramClient $client) {}

    public function normalizeRecipient(string $recipient): string
    {
        return $this->client->normalizeRecipient($recipient);
    }

    public function send(OutboxMessage $message): OutboxTransportResult
    {
        try {
            $payload = $this->normalizePayload($message->template, $message->payload ?? []);
        } catch (InvalidArgumentException) {
            return OutboxTransportResult::permanent('telegram.payload_invalid', 'validation', 'Telegram message configuration or payload is invalid.');
        }

        return $this->client->send($message->recipient, $payload['text']);
    }

    public function maskRecipient(string $recipient): string
    {
        if ($recipient === '') {
            return '—';
        }

        return '******'.mb_substr($recipient, -4);
    }
}
