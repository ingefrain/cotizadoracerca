<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionInvalidator
{
    /** @param list<int> $userIds */
    public function invalidate(array $userIds): void
    {
        $users = User::query()->whereKey($userIds)->get();
        foreach ($users as $user) {
            $user->forceFill([
                'remember_token' => Str::random(60),
                'session_version' => $user->session_version + 1,
            ])->saveQuietly();
        }

        if ($userIds !== []) {
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        }
    }
}
