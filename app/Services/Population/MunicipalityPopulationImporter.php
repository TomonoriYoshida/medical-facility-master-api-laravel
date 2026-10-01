<?php

namespace App\Services\Population;

use App\Models\MunicipalityPopulation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replaces municipality_populations with the latest 住民基本台帳人口.
 */
final class MunicipalityPopulationImporter
{
    public function __construct(
        private readonly MunicipalityPopulationClient $client,
    ) {}

    /**
     * @return array{as_of: Carbon, imported: int}|null null when that edition (or a newer one) is already imported, unless $force
     */
    public function import(bool $force = false): ?array
    {
        $latest = $this->client->latest();
        $current = MunicipalityPopulation::query()->max('as_of');

        // Never replace a newer edition with an older one the page still lists.
        if (! $force && $current !== null && $latest['as_of']->lte(Carbon::parse($current))) {
            return null;
        }

        // ~2,000 rows: read fully before the transaction, so a file that fails
        // to parse leaves the previous edition in place.
        $now = now();
        $rows = [];

        foreach ($this->client->populations($this->client->download($latest['url'])) as $code => $population) {
            $rows[] = [
                'municipality_code' => $code,
                'population' => $population,
                'as_of' => $latest['as_of']->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // The file covers all of Japan (~1,900 municipalities and wards); far
        // fewer rows means it was misread, so keep the previous edition.
        $minimum = config()->integer('population.minimum_municipalities');

        if (count($rows) < $minimum) {
            throw new RuntimeException('Only '.count($rows)." municipalities were read from {$latest['url']} (expected at least {$minimum}); the previous edition was kept.");
        }

        DB::transaction(function () use ($rows): void {
            MunicipalityPopulation::query()->delete();
            MunicipalityPopulation::query()->insert($rows);
        });

        Cache::forever(MunicipalityPopulation::IMPORTED_AT_CACHE_KEY, $now->getTimestamp());

        return ['as_of' => $latest['as_of'], 'imported' => count($rows)];
    }
}
