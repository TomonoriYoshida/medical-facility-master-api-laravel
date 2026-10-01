<?php

namespace App\Console\Commands;

use App\Models\MedicalFacility;
use App\Services\Address\MunicipalityResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Recomputes municipality_code for every facility from its address.
 * MedicalFacilityObserver only sets it when the address or prefecture
 * changes, so facilities imported before the municipalities table was
 * seeded (or after MunicipalitySeeder was re-run with a refreshed CSV)
 * keep their old value until this is run.
 *
 * Writes through the query builder rather than save(): municipality_code
 * is derived, so updated_at (which reflects source-data changes) must not
 * move.
 */
#[Signature('facilities:assign-municipalities')]
#[Description('Recompute municipality_code for every medical facility from its address (run after seeding or updating municipalities)')]
class AssignMunicipalities extends Command
{
    private const int CHUNK_SIZE = 1000;

    public function handle(MunicipalityResolver $resolver): int
    {
        $updatedCount = 0;
        $unresolvedCount = 0;

        MedicalFacility::query()
            ->select(['id', 'prefecture_code', 'address', 'municipality_code'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($resolver, &$updatedCount, &$unresolvedCount): void {
                foreach ($facilities as $facility) {
                    $municipalityCode = $resolver->resolve($facility->prefecture_code, $facility->address);

                    if ($municipalityCode === null) {
                        $unresolvedCount++;
                    }

                    if ($municipalityCode === $facility->municipality_code) {
                        continue;
                    }

                    MedicalFacility::query()->whereKey($facility->id)->toBase()->update(['municipality_code' => $municipalityCode]);

                    $updatedCount++;
                }
            });

        $this->components->info("{$updatedCount}件の施設の市区町村コードを更新しました（判定できなかった施設: {$unresolvedCount}件）。");

        $this->call('queue:restart');

        return Command::SUCCESS;
    }
}
