<?php

namespace App\Services\Outbox;

use App\Contracts\OutboxEmailSender;
use App\Contracts\OutboxTransport;
use App\Models\OutboxMessage;
use App\Support\OutboxPayload;
use InvalidArgumentException;
use Throwable;

final class EmailOutboxTransport implements OutboxTransport
{
    public function __construct(private readonly OutboxEmailSender $sender) {}

    public function normalizePayload(string $template, array $payload): array
    {
        if ($template !== 'generic.email.v1') {
            throw new InvalidArgumentException('Unsupported email template version.');
        }

        return OutboxPayload::normalize($payload);
    }

    public function normalizeRecipient(string $recipient): string
    {
        $recipient = mb_strtolower(trim($recipient));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email recipient is required.');
        }

        return $recipient;
    }

    public function send(OutboxMessage $message): OutboxTransportResult
    {
        try {
            $this->sender->send($this->normalizeRecipient($message->recipient), $this->normalizePayload($message->template, $message->payload ?? []));
        } catch (InvalidArgumentException) {
            return OutboxTransportResult::permanent('email.invalid', 'configuration', 'Email configuration or message is invalid; details redacted.');
        } catch (Throwable) {
            return OutboxTransportResult::retryable('email.delivery', 'delivery', 'Email delivery failed; details redacted.');
        }

        return OutboxTransportResult::accepted();
    }

    public function maskRecipient(string $recipient): string
    {
        [$local, $domain] = array_pad(explode('@', $recipient, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '—';
        }
        $domainParts = explode('.', $domain);
        $domainParts[0] = mb_substr($domainParts[0], 0, 1).'***';

        return mb_substr($local, 0, 1).'***@'.implode('.', $domainParts);
    }
}
