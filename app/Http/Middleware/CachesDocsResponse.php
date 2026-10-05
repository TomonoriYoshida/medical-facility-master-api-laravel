<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the rendered docs pages (the UI, which embeds the whole document,
 * and the OpenAPI JSON) as plain strings: Scramble analyzes the code to
 * build the document on every request (0.6-0.9s on the production server),
 * and its own cache (scramble:cache) restores the document as slowly as it
 * builds it. A string comes back in milliseconds.
 *
 * The document changes only with the code, so the key carries the time the
 * route cache was written, which the container's entrypoint does on every
 * start (a deploy). Without a route cache (local development) nothing is
 * cached, so the docs follow the code as it is edited.
 */
class CachesDocsResponse
{
    private const string CACHE_PREFIX = 'docs-response:';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->routesAreCached()) {
            return $next($request);
        }

        $key = self::CACHE_PREFIX.filemtime(app()->getCachedRoutesPath()).':'.$request->path();
        /** @var array{content: string, type: string}|null $cached */
        $cached = $this->store()->get($key);

        if ($cached !== null) {
            return response($cached['content'], 200, ['Content-Type' => $cached['type']]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 200 && is_string($response->getContent())) {
            $this->store()->put($key, [
                'content' => $response->getContent(),
                'type' => (string) $response->headers->get('Content-Type'),
            ], now()->addDays(30));
        }

        return $response;
    }

    private function store(): Repository
    {
        return Cache::store(config()->string('api.docs_cache_store'));
    }
}
