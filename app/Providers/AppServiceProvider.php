<?php

namespace App\Providers;

use App\Http\Middleware\ResolveApiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
        | Laravel's limiter is a fixed-window counter held in the cache. That
        | allows up to a 2x burst across a window edge, which is acceptable
        | here; a sliding window would be custom code for a small gain.
        |
        | The counter lives in Redis because the file driver has no atomic
        | increment: its increment() reads, modifies and writes with no lock, so
        | the count drifts low under exactly the concurrent load a rate limiter
        | exists to handle.
        |
        | The throttle middleware adds X-RateLimit-Limit, X-RateLimit-Remaining
        | and Retry-After for free.
        */
        RateLimiter::for('api', function (Request $request): Limit {
            $client = $request->attributes->get(ResolveApiClient::ATTRIBUTE);

            if ($client !== null) {
                return Limit::perMinute($client->rate_limit_per_minute)->by('client:'.$client->id);
            }

            return Limit::perMinute((int) config('kv.rate_limit.anonymous'))->by('ip:'.$request->ip());
        });
    }
}
