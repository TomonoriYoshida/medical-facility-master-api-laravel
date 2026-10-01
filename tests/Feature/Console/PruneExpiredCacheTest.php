<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneExpiredCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database']);
    }

    public function test_deletes_expired_rows_and_keeps_live_ones(): void
    {
        Cache::put('expired', 'value', 60);
        $this->travel(2)->minutes();
        Cache::put('live', 'value', 60);

        $this->artisan('cache:prune-expired')
            ->expectsOutput('Deleted 1 expired cache rows.')
            ->assertSuccessful();

        $this->assertSame(['live'], $this->storedKeys());
        $this->assertSame('value', Cache::get('live'));
    }

    public function test_deletes_expired_locks(): void
    {
        Cache::lock('expired-lock', 60)->get();
        $this->travel(2)->minutes();

        $this->artisan('cache:prune-expired')->assertSuccessful();

        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_deletes_a_backlog_larger_than_one_chunk(): void
    {
        DB::table('cache')->insert(array_map(fn (int $index): array => [
            'key' => "expired-{$index}",
            'value' => 'value',
            'expiration' => now()->subMinute()->getTimestamp(),
        ], range(1, 1500)));

        $this->artisan('cache:prune-expired')
            ->expectsOutput('Deleted 1500 expired cache rows.')
            ->assertSuccessful();
    }

    public function test_does_nothing_when_the_cache_store_is_not_a_database(): void
    {
        config(['cache.default' => 'array']);

        $this->artisan('cache:prune-expired')
            ->expectsOutput('The default cache store "array" is not a database store; nothing to prune.')
            ->assertSuccessful();
    }

    /**
     * @return list<string>
     */
    private function storedKeys(): array
    {
        $prefix = config()->string('cache.prefix');

        return DB::table('cache')->pluck('key')
            ->map(fn (string $key): string => substr($key, strlen($prefix)))
            ->values()
            ->all();
    }
}
