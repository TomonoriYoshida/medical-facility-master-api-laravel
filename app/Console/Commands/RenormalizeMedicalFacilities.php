<?php

namespace App\Console\Commands;

use App\Models\MedicalFacility;
use App\Services\Text\AddressNormalizer;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Recomputes name_normalized/address_normalized for every facility.
 * MedicalFacilityObserver only recomputes them when name/address
 * themselves change, so after kanji_variants is updated (re-run
 * KanjiVariantSeeder) existing rows keep their old normalized values
 * until this is run.
 *
 * Writes through the query builder rather than save(): these are derived
 * search columns, so updated_at (which reflects source-data changes) must
 * not move. Finally signals queue workers to restart, since
 * ItaijiNormalizer memoizes the variant map for the life of the process.
 */
#[Signature('facilities:renormalize')]
#[Description('Recompute name_normalized/address_normalized for every medical facility (run after updating kanji_variants)')]
class RenormalizeMedicalFacilities extends Command
{
    private const int CHUNK_SIZE = 1000;

    public function handle(ItaijiNormalizer $itaijiNormalizer, AddressNormalizer $addressNormalizer): int
    {
        $updatedCount = 0;

        MedicalFacility::query()
            ->select(['id', 'name', 'address', 'name_normalized', 'address_normalized'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($itaijiNormalizer, $addressNormalizer, &$updatedCount): void {
                foreach ($facilities as $facility) {
                    $nameNormalized = $itaijiNormalizer->normalize($facility->name);
                    $addressNormalized = $addressNormalizer->normalize($facility->address);

                    if ($nameNormalized === $facility->name_normalized && $addressNormalized === $facility->address_normalized) {
                        continue;
                    }

                    MedicalFacility::query()->whereKey($facility->id)->toBase()->update([
                        'name_normalized' => $nameNormalized,
                        'address_normalized' => $addressNormalized,
                    ]);

                    $updatedCount++;
                }
            });

        $this->components->info("{$updatedCount}件の施設の検索用カラムを更新しました。");

        $this->call('queue:restart');

        return Command::SUCCESS;
    }
}
