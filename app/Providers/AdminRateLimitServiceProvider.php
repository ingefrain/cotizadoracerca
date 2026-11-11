<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AdminRateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('pdf-preview', fn (Request $request) => Limit::perMinute(10)
            ->by('pdf-preview:user:'.$request->user()?->id));

        RateLimiter::for('admin-password', fn (Request $request) => Limit::perMinute(5)
            ->by('admin-password:ip:'.$request->ip()));
    }
}
