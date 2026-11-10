<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

class AdminLoginLimiter
{
    private const WINDOW = 60;

    public function retryAfter(string $email, string $ip): int
    {
        $wait = 0;
        foreach ([$this->identityKey($email, $ip) => 5, $this->key('ip', $ip) => 30] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }

    public function recordFailure(string $email, string $ip): void
    {
        RateLimiter::hit($this->identityKey($email, $ip), self::WINDOW);
        RateLimiter::hit($this->key('ip', $ip), self::WINDOW);
    }

    public function clearIdentity(string $email, string $ip): void
    {
        RateLimiter::clear($this->identityKey($email, $ip));
    }

    private function identityKey(string $email, string $ip): string
    {
        return $this->key('identity-ip', $email."\0".$ip);
    }

    private function key(string $scope, string $value): string
    {
        return 'admin-login:'.hash_hmac('sha256', $scope."\0".$value, (string) config('app.key'));
    }
}
