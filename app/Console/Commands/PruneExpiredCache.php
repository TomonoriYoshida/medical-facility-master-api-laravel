<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes expired rows from the database cache store. Laravel removes an
 * expired row only when its key is read again, so keys that are never read
 * twice stay forever: one per distinct filter combination of the stats
 * endpoints (any date range, any designation_reason) and one rate limiter
 * counter per client IP. Without this, the cache table grows without bound,
 * and anyone can grow it faster by varying the query.
 */
#[Signature('cache:prune-expired')]
#[Description('Delete expired rows from the database cache store')]
class PruneExpiredCache extends Command
{
    private const int CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $store = config()->string('cache.default');
        $config = config()->array("cache.stores.{$store}");

        if (($config['driver'] ?? null) !== 'database') {
            $this->info("The default cache store \"{$store}\" is not a database store; nothing to prune.");

            return self::SUCCESS;
        }

        $now = now()->getTimestamp();
        $deleted = $this->prune($config['connection'] ?? null, $config['table'] ?? 'cache', $now)
            + $this->prune($config['lock_connection'] ?? $config['connection'] ?? null, $config['lock_table'] ?? 'cache_locks', $now);

        $this->info("Deleted {$deleted} expired cache rows.");

        return self::SUCCESS;
    }

    /**
     * Deletes in chunks, so a large backlog never holds one long lock on a
     * table that every API request writes to (the rate limiter).
     */
    private function prune(?string $connection, string $table, int $now): int
    {
        $deleted = 0;

        do {
            $count = DB::connection($connection)->table($table)
                ->where('expiration', '<=', $now)
                ->limit(self::CHUNK_SIZE)
                ->delete();
            $deleted += $count;
        } while ($count === self::CHUNK_SIZE);

        return $deleted;
    }
}
