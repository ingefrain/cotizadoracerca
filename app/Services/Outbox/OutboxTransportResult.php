<?php

namespace App\Services\Outbox;

use Carbon\CarbonInterface;
use InvalidArgumentException;

final class OutboxTransportResult
{
    public const ACCEPTED = 'accepted';

    public const RETRYABLE_FAILURE = 'retryable_failure';

    public const PERMANENT_FAILURE = 'permanent_failure';

    public const UNCERTAIN = 'uncertain';

    private function __construct(
        public readonly string $status,
        public readonly ?string $code = null,
        public readonly ?string $providerReference = null,
        public readonly ?CarbonInterface $retryAt = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function accepted(?string $providerReference = null): self
    {
        return new self(self::ACCEPTED, providerReference: self::safeIdentifier($providerReference));
    }

    public static function retryable(string $code, string $errorType, string $safeMessage, ?CarbonInterface $retryAt = null, ?string $providerReference = null): self
    {
        return self::failure(self::RETRYABLE_FAILURE, $code, $errorType, $safeMessage, $retryAt, $providerReference);
    }

    public static function permanent(string $code, string $errorType, string $safeMessage, ?string $providerReference = null): self
    {
        return self::failure(self::PERMANENT_FAILURE, $code, $errorType, $safeMessage, providerReference: $providerReference);
    }

    public static function uncertain(string $code, string $errorType, string $safeMessage, ?string $providerReference = null): self
    {
        return self::failure(self::UNCERTAIN, $code, $errorType, $safeMessage, providerReference: $providerReference);
    }

    private static function failure(
        string $status,
        string $code,
        string $errorType,
        string $message,
        ?CarbonInterface $retryAt = null,
        ?string $providerReference = null,
    ): self {
        foreach ([$code, $errorType] as $identifier) {
            if (! preg_match('/\A[a-z0-9][a-z0-9_.-]{0,79}\z/i', $identifier)) {
                throw new InvalidArgumentException('Unsafe transport result identifier.');
            }
        }
        if (mb_strlen($message) > 255 || preg_match('~\b[a-z][a-z0-9+.-]*://|(?:password|token|secret)\s*[=:]~i', $message)) {
            throw new InvalidArgumentException('Transport error messages must be safe and sanitized.');
        }

        return new self(
            $status,
            $code,
            self::safeIdentifier($providerReference),
            $retryAt,
            $errorType,
            $message,
        );
    }

    private static function safeIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,99}\z/', $identifier)) {
            throw new InvalidArgumentException('Unsafe provider reference.');
        }

        return $identifier;
    }
}
