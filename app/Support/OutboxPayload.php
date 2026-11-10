<?php

namespace App\Support;

use InvalidArgumentException;

final class OutboxPayload
{
    private const MAX_BYTES = 8192;

    private const MAX_LENGTHS = [
        'subject' => 180,
        'heading' => 180,
        'greeting' => 300,
        'message' => 4000,
        'action_label' => 80,
        'action_url' => 2048,
    ];

    /** @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public static function normalize(array $payload): array
    {
        $unknown = array_diff(array_keys($payload), array_keys(self::MAX_LENGTHS));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unsupported email payload fields.');
        }

        foreach (['subject', 'message'] as $required) {
            if (! isset($payload[$required]) || ! is_string($payload[$required]) || trim($payload[$required]) === '') {
                throw new InvalidArgumentException('Email subject and message are required.');
            }
        }
        if (isset($payload['action_label']) !== isset($payload['action_url'])) {
            throw new InvalidArgumentException('Email action label and URL must be supplied together.');
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Email content must be valid UTF-8 text.');
            }
            $value = trim($value);
            if (mb_strlen($value) > self::MAX_LENGTHS[$key]) {
                throw new InvalidArgumentException('Email content exceeds its allowed length.');
            }
            if ($key === 'subject' && preg_match('/[\r\n]/', $value)) {
                throw new InvalidArgumentException('Email subject contains invalid characters.');
            }
            $normalized[$key] = $value;
        }

        if (isset($normalized['action_url'])) {
            $url = parse_url($normalized['action_url']);
            if (! is_array($url)
                || ! filter_var($normalized['action_url'], FILTER_VALIDATE_URL)
                || ! in_array(strtolower((string) ($url['scheme'] ?? '')), ['http', 'https'], true)
                || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment'])) {
                throw new InvalidArgumentException('Email action URL is not allowed.');
            }
        }

        if (strlen((string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Email payload exceeds its allowed size.');
        }

        return $normalized;
    }
}
