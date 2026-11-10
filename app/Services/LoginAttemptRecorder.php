<?php

namespace App\Services;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;

class LoginAttemptRecorder
{
    public function record(Request $request, string $result, string $reason, ?User $user = null): LoginAttempt
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $userAgent = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $request->userAgent()) ?? '';
        $userAgent = mb_substr(trim($userAgent), 0, 512);

        return LoginAttempt::query()->create([
            'user_id' => $user?->id,
            'email' => $email === '' ? null : mb_substr($email, 0, 255),
            'ip_address' => $request->ip() ? mb_substr($request->ip(), 0, 45) : null,
            'user_agent' => $userAgent === '' ? null : $userAgent,
            'result' => $result,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
