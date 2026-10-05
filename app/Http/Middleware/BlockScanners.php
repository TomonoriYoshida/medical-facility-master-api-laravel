<?php

namespace App\Http\Middleware;

use App\Services\AccessLog\ScannerPaths;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns away a client for a while (api.scanner_block.hours) once it asks for
 * a path only a vulnerability scanner does (ScannerPaths): every request it
 * sends until then, the API included, gets 403. The probe itself gets the
 * same 404 as any unknown path, so as not to tell the scanner it was seen.
 *
 * Blocked clients are kept in their own cache store (the file store by
 * default): every request reads it, and the default database store would
 * add a query to each. A deploy that replaces the container clears them.
 */
class BlockScanners
{
    private const string CACHE_PREFIX = 'scanner-block:';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hours = config()->integer('api.scanner_block.hours');
        $ip = (string) $request->ip();

        if ($hours <= 0) {
            return $next($request);
        }

        if ($this->store()->has(self::CACHE_PREFIX.$ip)) {
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => 'アクセスが制限されています。'], 403)
                : response('Forbidden', 403);
        }

        if (ScannerPaths::matches($request->path())) {
            $this->store()->put(self::CACHE_PREFIX.$ip, true, now()->addHours($hours));
            Log::warning("scanner-block: {$ip} を{$hours}時間遮断しました", ['path' => '/'.ltrim($request->path(), '/')]);

            abort(404);
        }

        return $next($request);
    }

    private function store(): Repository
    {
        return Cache::store(config()->string('api.scanner_block.store'));
    }
}
