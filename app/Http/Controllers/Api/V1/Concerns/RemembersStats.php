<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

trait RemembersStats
{
    /** The data changes once a day (the import), so an hour of staleness is harmless. */
    private const int CACHE_SECONDS = 3600;

    /**
     * Caches an aggregate for an hour. Computing one scans every matching
     * facility, and any new date range or designation_reason misses the
     * cache, so only the computations (not the cached answers a dashboard
     * mostly gets) count toward a separate, smaller per-IP limit than the
     * API's own.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $compute
     * @return TResult
     */
    private function rememberStats(Request $request, string $key, Closure $compute): mixed
    {
        if (! Cache::has($key)) {
            $limiterKey = 'stats-computations:'.$request->ip();
            $maxAttempts = config()->integer('api.stats_computations_per_minute');

            if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
                $retryAfter = RateLimiter::availableIn($limiterKey);

                throw new ThrottleRequestsException('Too Many Attempts.', headers: [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $maxAttempts,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }

            RateLimiter::hit($limiterKey);
        }

        return Cache::remember($key, self::CACHE_SECONDS, $compute);
    }
}
