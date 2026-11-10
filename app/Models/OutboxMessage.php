<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboxMessage extends Model
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const STATUSES = [self::PENDING, self::PROCESSING, self::SENT, self::FAILED, self::UNCERTAIN];

    protected $fillable = [
        'uuid', 'category', 'channel', 'recipient', 'recipient_hash', 'template', 'payload',
        'status', 'attempts', 'retry_generation', 'idempotency_key', 'correlation_id',
        'available_at', 'lease_token', 'lease_expires_at', 'processed_at', 'sent_at',
        'last_error_type', 'last_error_code', 'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'retry_generation' => 'integer',
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'processed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function attemptsHistory(): HasMany
    {
        return $this->hasMany(OutboxAttempt::class)->orderByDesc('attempt_number');
    }

    public function scopeReady(Builder $query): Builder
    {
        $now = now();

        return $query->where(function ($ready) use ($now): void {
            $ready->where(function ($pending) use ($now): void {
                $pending->where('status', self::PENDING)->where('available_at', '<=', $now);
            })->orWhere(function ($expired): void {
                $expired->where('status', self::PROCESSING)->where('lease_expires_at', '<=', now());
            });
        });
    }
}
