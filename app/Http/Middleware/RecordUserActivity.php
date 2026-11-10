<?php

namespace App\Http\Middleware;

use App\Support\ImpersonationManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RecordUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(ImpersonationManager::class)->active()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user) {
            $now = now();

            $updated = DB::table('users')
                ->where('id', $user->id)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('last_activity_at')
                        ->orWhere('last_activity_at', '<=', $now->copy()->subMinutes(5));
                })
                ->update(['last_activity_at' => $now]);

            if ($updated > 0) {
                $user->setAttribute('last_activity_at', $now);
            }
        }

        return $next($request);
    }
}
