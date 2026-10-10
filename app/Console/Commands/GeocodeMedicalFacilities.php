<?php

namespace App\Console\Commands;

use App\Enums\GeocodeLevel;
use App\Models\MedicalFacility;
use App\Services\Geocoding\FacilityGeocoder;
use App\Services\Geocoding\GeocodeResult;
use App\Services\Geocoding\MedicalInfoNetLocator;
use App\Services\Geocoding\NationalLandLocator;
use App\Services\Rhb\RhbScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sets latitude/longitude/geocode_level from each facility's address (see
 * FacilityGeocoder, and MedicalInfoNetLocator then NationalLandLocator for
 * where the registry only reaches the 町丁目), for the facilities whose address changed since it
 * was last geocoded -- new facilities and moves, so usually only a few a
 * day. --all redoes every facility, e.g. after the registry's position
 * data has grown.
 *
 * A changed location moves updated_at, so that clients syncing with
 * updated_since pick it up and rhb:export rebuilds its files. An address
 * that could not be located is still marked as geocoded (location null)
 * so that it is not retried every day; --all retries it.
 */
#[Signature('facilities:geocode
    {--all : 住所が変わっていない施設も含め、すべての施設の座標を付け直す}')]
#[Description('Locate medical facilities from their addresses with the Address Base Registry')]
class GeocodeMedicalFacilities extends Command
{
    private const int WRITE_CHUNK_SIZE = 1000;

    public function handle(FacilityGeocoder $geocoder, MedicalInfoNetLocator $medicalInfoNet, NationalLandLocator $nationalLand, RhbScope $scope): int
    {
        $failed = false;

        foreach ($scope->prefectures() as $prefecture) {
            /** @var array<int, array{institution_type: int, municipality_code: ?string, name: string, address: string}> $facilities */
            $facilities = MedicalFacility::query()
                ->where('prefecture_code', $prefecture->value)
                ->unless($this->option('all'), fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('geocoded_address')
                    ->orWhereColumn('geocoded_address', '!=', 'address')))
                ->toBase()
                ->get(['id', 'institution_type', 'municipality_code', 'name', 'address'])
                ->mapWithKeys(fn (object $row): array => [(int) $row->id => [
                    'institution_type' => (int) $row->institution_type,
                    'municipality_code' => $row->municipality_code,
                    'name' => (string) $row->name,
                    'address' => (string) $row->address,
                ]])
                ->all();

            if ($facilities === []) {
                continue;
            }

            $addresses = array_map(fn (array $facility): string => $facility['address'], $facilities);

            try {
                $results = $nationalLand->refine($prefecture, $facilities, $medicalInfoNet->refine($prefecture, $facilities, $geocoder->geocode($prefecture, $addresses)));
                $counts = $this->store($results, $addresses);
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error("{$prefecture->label()}: {$exception->getMessage()}");
                $failed = true;

                continue;
            }

            // One plain line: twoColumnDetail() truncates to the terminal width.
            $this->components->info($prefecture->label().'（'.count($addresses).'件）: '
                .collect($counts)->map(fn (int $count, string $label): string => "{$label} {$count}")->implode(' / '));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array<int, GeocodeResult|null>  $results
     * @param  array<int, string>  $addresses
     * @return array<string, int> level label => count, plus 判定不能
     */
    private function store(array $results, array $addresses): array
    {
        $counts = array_fill_keys([...array_map(fn (GeocodeLevel $level): string => $level->label(), GeocodeLevel::cases()), '判定不能'], 0);
        $current = [];

        foreach (array_chunk(array_keys($results), self::WRITE_CHUNK_SIZE) as $ids) {
            MedicalFacility::query()
                ->whereKey($ids)
                ->toBase()
                ->get(['id', 'latitude', 'longitude', 'geocode_level'])
                ->each(function (object $row) use (&$current): void {
                    $current[$row->id] = [$row->latitude, $row->longitude, $row->geocode_level];
                });

            DB::transaction(function () use ($ids, $results, $addresses, $current, &$counts): void {
                foreach ($ids as $id) {
                    $result = $results[$id];
                    $counts[$result?->level->label() ?? '判定不能']++;

                    $location = $result === null ? [null, null, null] : [
                        number_format($result->latitude, 6, '.', ''),
                        number_format($result->longitude, 6, '.', ''),
                        $result->level->value,
                    ];
                    $changed = $location != ($current[$id] ?? [null, null, null]);

                    // Only while the address is still the one that was geocoded: the
                    // import's queue worker may have moved the facility meanwhile
                    // (its new address is then left for the next run). Compared
                    // in binary, since the column's collation ignores width.
                    MedicalFacility::query()->whereKey($id)->whereRaw('address = ? COLLATE utf8mb4_bin', [$addresses[$id]])->toBase()->update([
                        'geocoded_address' => $addresses[$id],
                        ...($changed ? [
                            'latitude' => $location[0],
                            'longitude' => $location[1],
                            'geocode_level' => $location[2],
                            'updated_at' => now(),
                        ] : []),
                    ]);
                }
            });
        }

        return $counts;
    }
}
