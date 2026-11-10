<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginAttempt extends Model
{
    public const SUCCESS = 'success';

    public const INVALID_CREDENTIALS = 'invalid_credentials';

    public const INACTIVE_ACCOUNT = 'inactive_account';

    public const RATE_LIMITED = 'rate_limited';

    public const RESULTS = [
        self::SUCCESS,
        self::INVALID_CREDENTIALS,
        self::INACTIVE_ACCOUNT,
        self::RATE_LIMITED,
    ];

    public $timestamps = false;

    protected $fillable = ['user_id', 'email', 'ip_address', 'user_agent', 'result', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
