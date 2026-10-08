<?php

namespace Tests\Feature\Http\Middleware;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CachesDocsResponseTest extends TestCase
{
    private string $routeCachePath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.docs_cache_store' => 'array']);
        $this->routeCachePath = $this->app->getCachedRoutesPath();
    }

    protected function tearDown(): void
    {
        if (is_file($this->routeCachePath) && file_get_contents($this->routeCachePath) === '<?php // test') {
            unlink($this->routeCachePath);
        }

        parent::tearDown();
    }

    public function test_the_rendered_pages_are_served_from_the_cache_once_routes_are_cached(): void
    {
        $this->cacheRoutes();

        $first = $this->get('/docs/api.json');
        $first->assertOk();
        $this->assertStringContainsString('"openapi"', (string) $first->getContent());
        $this->assertStringContainsString('max-age=3600', (string) $first->headers->get('Cache-Control'));
        $this->assertTrue($first->headers->has('ETag'));

        // Whatever the cache holds is what is served.
        Cache::store('array')->put($this->key('docs/api.json'), ['content' => '{"cached":true}', 'type' => 'application/json'], 60);

        $this->get('/docs/api.json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['cached' => true]);
    }

    public function test_a_new_route_cache_from_a_deploy_leaves_the_old_pages_behind(): void
    {
        $this->cacheRoutes();
        Cache::store('array')->put($this->key('docs/api.json'), ['content' => '{"cached":true}', 'type' => 'application/json'], 60);

        touch($this->routeCachePath, time() + 60);
        clearstatcache();

        $this->assertStringContainsString('"openapi"', (string) $this->get('/docs/api.json')->getContent());
    }

    public function test_nothing_is_cached_without_a_route_cache(): void
    {
        $this->get('/docs/api')->assertOk();

        $this->assertSame([], array_filter(
            array_keys((array) Cache::store('array')->getStore()->all(false)),
            fn (string $key): bool => str_starts_with($key, 'docs-response:'),
        ));
    }

    public function test_the_warm_command_renders_both_pages(): void
    {
        $this->cacheRoutes();

        $this->artisan('docs:warm')->assertExitCode(0);

        $this->assertTrue(Cache::store('array')->has($this->key('docs/api')));
        $this->assertTrue(Cache::store('array')->has($this->key('docs/api.json')));
    }

    public function test_the_warm_command_points_try_it_at_the_app_url(): void
    {
        config(['app.url' => 'https://api.example.test/']);
        $this->cacheRoutes();

        $this->artisan('docs:warm')->assertExitCode(0);

        /** @var array{content: string, type: string} $cached */
        $cached = Cache::store('array')->get($this->key('docs/api.json'));
        $this->assertSame([['url' => 'https://api.example.test/api']], json_decode($cached['content'], true)['servers']);
    }

    /**
     * A stand-in route cache file: only its time is read here, the routes
     * themselves were registered at boot. The application remembers at
     * boot whether routes are cached, so that is set too.
     */
    private function cacheRoutes(): void
    {
        $this->assertFileDoesNotExist($this->routeCachePath, 'A real route cache exists; run route:clear first.');
        file_put_contents($this->routeCachePath, '<?php // test');
        $this->app->instance('routes.cached', true);
    }

    private function key(string $path): string
    {
        clearstatcache();

        return 'docs-response:'.filemtime($this->routeCachePath).':'.$path;
    }
}
