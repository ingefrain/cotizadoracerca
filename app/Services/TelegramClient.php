<?php

namespace App\Services;

use App\Services\Outbox\OutboxTransportResult;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class TelegramClient
{
    public function normalizeRecipient(string $recipient): string
    {
        $recipient = trim($recipient);
        if (! preg_match('/\A-?[1-9][0-9]{0,15}\z/', $recipient)) {
            throw new InvalidArgumentException('Telegram recipient must be a numeric chat identifier.');
        }

        $allowed = array_map('strval', (array) config('services.telegram.allowed_chat_ids', []));
        if (! in_array($recipient, $allowed, true)) {
            throw new InvalidArgumentException('Telegram recipient is not allowlisted.');
        }

        return $recipient;
    }

    public function send(string $recipient, string $text): OutboxTransportResult
    {
        $token = config('services.telegram.bot_token');
        $connectTimeout = (int) config('services.telegram.connect_timeout', 5);
        $timeout = (int) config('services.telegram.timeout', 10);

        if (! config('services.telegram.enabled') || ! is_string($token) || trim($token) === '') {
            return OutboxTransportResult::permanent('telegram.configuration_missing', 'configuration', 'Telegram is disabled or its configuration is incomplete.');
        }
        if ($connectTimeout < 1 || $timeout < $connectTimeout) {
            return OutboxTransportResult::permanent('telegram.timeout_configuration', 'configuration', 'Telegram timeout configuration is invalid.');
        }

        try {
            $recipient = $this->normalizeRecipient($recipient);
            if (! mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text) > 4096) {
                throw new InvalidArgumentException('Telegram text is invalid.');
            }
        } catch (InvalidArgumentException $exception) {
            $error = str_contains($exception->getMessage(), 'allowlisted')
                ? ['telegram.destination_denied', 'Telegram recipient is not allowed.']
                : ['telegram.payload_invalid', 'Telegram message configuration or payload is invalid.'];

            return OutboxTransportResult::permanent($error[0], 'validation', $error[1]);
        }

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $recipient,
                    'text' => $text,
                ]);
        } catch (ConnectionException $exception) {
            if ($exception->getPrevious() instanceof ConnectException) {
                return OutboxTransportResult::retryable('telegram.connection_failed', 'connection', 'Telegram could not be reached before sending.');
            }

            return OutboxTransportResult::uncertain('telegram.delivery_uncertain', 'delivery', 'Telegram may have received the message, but acceptance could not be confirmed.');
        } catch (Throwable) {
            return OutboxTransportResult::uncertain('telegram.delivery_uncertain', 'delivery', 'Telegram may have received the message, but acceptance could not be confirmed.');
        }

        $body = $response->json();
        $telegramCode = is_array($body) && is_int($body['error_code'] ?? null) ? $body['error_code'] : null;
        $rateLimitCode = $response->status() === 429 || $telegramCode === 429;
        if ($rateLimitCode) {
            $retryAfter = data_get($body, 'parameters.retry_after');
            $retryAfter = filter_var($retryAfter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $retryAt = $retryAfter === false ? null : CarbonImmutable::now()->addSeconds($retryAfter);

            return OutboxTransportResult::retryable('telegram.rate_limited', 'rate_limit', 'Telegram rate limit reached.', $retryAt);
        }

        if ($response->serverError() || ($telegramCode !== null && $telegramCode >= 500)) {
            return OutboxTransportResult::retryable('telegram.server_error', 'provider', 'Telegram is temporarily unavailable.');
        }

        if (! $response->successful()) {
            $code = match (true) {
                $response->status() === 401, $telegramCode === 401 => 'telegram.authentication_rejected',
                $response->status() === 403, $telegramCode === 403 => 'telegram.chat_rejected',
                default => 'telegram.request_rejected',
            };

            return OutboxTransportResult::permanent($code, 'provider', 'Telegram rejected the request.');
        }

        if (! is_array($body) || ($body['ok'] ?? null) !== true) {
            if (($body['ok'] ?? null) === false) {
                return OutboxTransportResult::permanent('telegram.request_rejected', 'provider', 'Telegram rejected the request.');
            }

            return OutboxTransportResult::uncertain('telegram.response_invalid', 'response', 'Telegram response could not confirm whether the message was accepted.');
        }

        $messageId = data_get($body, 'result.message_id');
        $chatId = data_get($body, 'result.chat.id');
        if (! is_int($messageId) || $messageId < 1 || (! is_int($chatId) && ! is_string($chatId))) {
            return OutboxTransportResult::uncertain('telegram.response_incomplete', 'response', 'Telegram response could not confirm whether the message was accepted.');
        }

        return OutboxTransportResult::accepted((string) $messageId);
    }
}
