<?php

namespace App\Services\NationalLand;

use App\Enums\InstitutionType;
use App\Enums\Prefecture;
use App\Models\NationalLandMedicalLocation;
use App\Services\Address\FacilityMatchingKeys;
use App\Services\Address\MunicipalityResolver;
use App\Services\Http\OpenDataHttp;
use App\Services\Rhb\RhbScope;
use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Replaces national_land_medical_locations with the 国土数値情報「医療機関」
 * of every in-scope prefecture: for each facility, its matching keys and its
 * position. Its addresses start at the municipality (no prefecture) and it
 * has no municipality code, so the municipality is read off the address; a
 * facility whose municipality is not found (merged since, or left out of the
 * address) is skipped. The address key is made from what follows the
 * municipality, since the data writes some municipalities differently from
 * the bureaus (Yokohama's wards without the city): NationalLandLocator
 * compares it with the same part of the facility's address.
 */
final class NationalLandImporter
{
    private const string DIRECTORY = 'national-land';

    private const int INSERT_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly OpenDataHttp $http,
        private readonly FacilityMatchingKeys $keys,
        private readonly MunicipalityResolver $municipalities,
        private readonly RhbScope $scope,
    ) {}

    /**
     * @return array{imported: int, skipped: int}
     */
    public function import(): array
    {
        // Downloaded first, so that a failed download leaves the table as it was.
        $files = array_map(
            fn (Prefecture $prefecture): array => [$prefecture, $this->http->download(
                str_replace('{prefecture}', $prefecture->value, config()->string('national_land.file_url')),
                self::DIRECTORY,
                timeout: 120,
            )],
            $this->scope->prefectures(),
        );

        return DB::transaction(function () use ($files): array {
            NationalLandMedicalLocation::query()->delete();
            $now = now();
            $imported = 0;
            $skipped = 0;
            $pending = [];

            foreach ($files as [$prefecture, $path]) {
                foreach ($this->features($path) as $feature) {
                    $location = $this->location($prefecture, $feature);

                    if ($location === null) {
                        $skipped++;

                        continue;
                    }

                    $pending[] = [...$location, 'created_at' => $now, 'updated_at' => $now];

                    if (count($pending) === self::INSERT_CHUNK_SIZE) {
                        NationalLandMedicalLocation::query()->insert($pending);
                        $imported += count($pending);
                        $pending = [];
                    }
                }
            }

            NationalLandMedicalLocation::query()->insert($pending);

            return ['imported' => $imported + count($pending), 'skipped' => $skipped];
        });
    }

    /**
     * The features of the GeoJSON in a downloaded zip (which also holds the
     * same data as Shapefile and GML).
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function features(string $zipPath): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open zip \"{$zipPath}\".");
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_ends_with((string) $zip->getNameIndex($index), '.geojson')) {
                    $geoJson = json_decode((string) $zip->getFromIndex($index), true, flags: JSON_THROW_ON_ERROR);

                    yield from $geoJson['features'] ?? [];

                    return;
                }
            }

            throw new RuntimeException("No GeoJSON in \"{$zipPath}\".");
        } finally {
            $zip->close();
        }
    }

    /**
     * P04_001 is the type (1 病院, 2 一般診療所, 3 歯科診療所: the same codes as
     * InstitutionType), P04_002 the name and P04_003 the address.
     *
     * @param  array<string, mixed>  $feature
     * @return array{institution_type: int, municipality_code: string, name_key: string, address_key: string, latitude: float, longitude: float}|null
     */
    private function location(Prefecture $prefecture, array $feature): ?array
    {
        $properties = $feature['properties'] ?? [];
        $institutionType = InstitutionType::tryFrom((int) ($properties['P04_001'] ?? 0));
        $name = (string) ($properties['P04_002'] ?? '');
        $address = (string) ($properties['P04_003'] ?? '');
        $coordinates = ($feature['geometry']['type'] ?? null) === 'Point' ? $feature['geometry']['coordinates'] ?? [] : [];

        if ($institutionType === null || $institutionType === InstitutionType::Pharmacy || $name === ''
            || ! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
            return null;
        }

        $municipality = $this->municipalities->match($prefecture->value, $address);

        if ($municipality === null) {
            return null;
        }

        [$longitude, $latitude] = [(float) $coordinates[0], (float) $coordinates[1]];

        return [
            'institution_type' => $institutionType->value,
            'municipality_code' => $municipality['code'],
            'name_key' => $this->keys->name($name),
            'address_key' => $this->keys->address($municipality['remainder']),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }
}
