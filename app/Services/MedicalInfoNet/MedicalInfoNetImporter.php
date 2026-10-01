<?php

namespace App\Services\MedicalInfoNet;

use App\Models\MedicalInfoNetLocation;
use App\Services\Address\FacilityMatchingKeys;
use App\Services\Rhb\RhbScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replaces medical_info_net_locations with the latest 医療情報ネット
 * publication: for each facility in an in-scope prefecture, its matching
 * keys and its position. Facilities listed with "0.0" (about 8% of the
 * source) are kept with no position, so that MedicalInfoNetLocator does not
 * mistake a namesake for them.
 */
final class MedicalInfoNetImporter
{
    private const int INSERT_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly MedicalInfoNetClient $client,
        private readonly FacilityMatchingKeys $keys,
        private readonly RhbScope $scope,
    ) {}

    /**
     * @return array{published_on: Carbon, imported: int, located: int}|null null when that publication is already imported (unless $force)
     */
    public function import(bool $force = false): ?array
    {
        $latest = $this->client->latest();
        $current = MedicalInfoNetLocation::query()->max('published_on');

        if (! $force && $current !== null && Carbon::parse($current)->isSameDay($latest['published_on'])) {
            return null;
        }

        // Streamed into the table a chunk at a time (all of Japan is ~190,000
        // rows, too many to hold within PHP's default 128MB), inside one
        // transaction so the API never sees a half-replaced table.
        [$imported, $located] = DB::transaction(function () use ($latest): array {
            MedicalInfoNetLocation::query()->delete();
            $now = now();
            $chunk = [];
            $imported = 0;
            $located = 0;

            foreach ($latest['files'] as $file) {
                foreach ($this->client->rows($this->client->download($file['url'])) as $row) {
                    $location = $this->location($row);

                    if ($location === null || ! $this->scope->includesPrefecture(substr($location['municipality_code'], 0, 2))) {
                        continue;
                    }

                    $located += $location['latitude'] !== null ? 1 : 0;
                    $chunk[] = [
                        ...$location,
                        'institution_type' => $file['institution_type']->value,
                        'published_on' => $latest['published_on']->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($chunk) === self::INSERT_CHUNK_SIZE) {
                        MedicalInfoNetLocation::query()->insert($chunk);
                        $imported += count($chunk);
                        $chunk = [];
                    }
                }
            }

            MedicalInfoNetLocation::query()->insert($chunk);

            return [$imported + count($chunk), $located];
        });

        return ['published_on' => $latest['published_on'], 'imported' => $imported, 'located' => $located];
    }

    /**
     * Pharmacies call the name column 名称, the others 正式名称.
     *
     * @param  array<string, string>  $row
     * @return array{municipality_code: string, name_key: string, address_key: string, latitude: ?float, longitude: ?float}|null
     */
    private function location(array $row): ?array
    {
        $latitude = (float) ($row['所在地座標（緯度）'] ?? 0);
        $longitude = (float) ($row['所在地座標（経度）'] ?? 0);
        $name = $row['正式名称'] ?? $row['名称'] ?? '';
        $prefectureCode = $row['都道府県コード'] ?? '';
        $cityCode = $row['市区町村コード'] ?? '';

        if ($name === '' || $prefectureCode === '' || $cityCode === '') {
            return null;
        }

        $hasPosition = $latitude !== 0.0 && $longitude !== 0.0;

        return [
            'municipality_code' => str_pad($prefectureCode, 2, '0', STR_PAD_LEFT).str_pad($cityCode, 3, '0', STR_PAD_LEFT),
            'name_key' => $this->keys->name($name),
            'address_key' => $this->keys->address($row['所在地'] ?? ''),
            'latitude' => $hasPosition ? $latitude : null,
            'longitude' => $hasPosition ? $longitude : null,
        ];
    }
}
