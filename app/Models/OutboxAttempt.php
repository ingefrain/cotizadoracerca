<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'outbox_message_id', 'attempt_number', 'lease_token', 'result',
        'error_type', 'error_code', 'error_message', 'provider_reference', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }
}
