<?php

namespace App\Services\NationalLand;

use App\Models\MedicalFacility;
use App\Models\RailwayStation;
use App\Services\Http\OpenDataHttp;
use App\Services\Text\StationNameNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Replaces railway_stations with the stations of the 国土数値情報「鉄道」.
 * Each station of a line is a line along its platform, at times split
 * into pieces sharing the station code; the station is placed at the
 * average of their middle points. The data names no prefecture or
 * municipality, so the stations of a group (one station served by several
 * lines) all take those of the located facility nearest to the group
 * within NEARBY_DEGREES (null when there is none, as for stations outside
 * the prefectures in scope); near a boundary this can be the neighbor's,
 * which is fine for telling same-named stations apart. One query per
 * group (about 9,000) takes some 20 seconds, fine for a yearly import.
 */
final class RailwayStationImporter
{
    private const string DIRECTORY = 'national-land';

    private const int INSERT_CHUNK_SIZE = 1000;

    /** About 3 km of latitude. */
    private const float NEARBY_DEGREES = 0.03;

    public function __construct(
        private readonly OpenDataHttp $http,
        private readonly StationNameNormalizer $names,
    ) {}

    /**
     * @return array{imported: int, located: int, skipped: int}
     */
    public function import(): array
    {
        // Downloaded and prepared first, so that a failure leaves the table as it was.
        $path = $this->http->download(config()->string('national_land.stations.file_url'), self::DIRECTORY, timeout: 120);
        ['stations' => $stations, 'skipped' => $skipped] = $this->stations($path);
        $areas = [];

        foreach (collect($stations)->groupBy('group_code') as $groupCode => $group) {
            $areas[$groupCode] = $this->nearestArea((float) $group->avg('latitude'), (float) $group->avg('longitude'));
        }

        $now = now();
        $rows = array_map(fn (array $station): array => [
            ...$station,
            'prefecture_code' => $areas[$station['group_code']]['prefecture_code'] ?? null,
            'municipality_code' => $areas[$station['group_code']]['municipality_code'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $stations);

        DB::transaction(function () use ($rows): void {
            RailwayStation::query()->delete();

            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
                RailwayStation::query()->insert($chunk);
            }
        });

        return [
            'imported' => count($rows),
            'located' => count(array_filter($rows, fn (array $row): bool => $row['prefecture_code'] !== null)),
            'skipped' => $skipped,
        ];
    }

    /**
     * The stations of the zip's Station GeoJSON, one per station code.
     * N02_003 is the line, N02_004 the operator, N02_005 the name,
     * N02_005c the station code and N02_005g the code shared by the
     * stations of a group (one station served by several lines).
     *
     * Features without a code, a name or a LineString are counted as
     * skipped, so that a change in the data's shape does not go unnoticed.
     *
     * @return array{stations: list<array{station_code: string, group_code: string, name: string, name_normalized: string, line_name: string, operator_name: string, latitude: float, longitude: float}>, skipped: int}
     */
    private function stations(string $zipPath): array
    {
        $stations = [];
        $middles = [];
        $skipped = 0;

        foreach ($this->features($zipPath) as $feature) {
            $properties = $feature['properties'] ?? [];
            $code = (string) ($properties['N02_005c'] ?? '');
            $name = (string) ($properties['N02_005'] ?? '');
            $middle = $this->middle($feature['geometry'] ?? []);

            if ($code === '' || $name === '' || $middle === null) {
                $skipped++;

                continue;
            }

            $middles[$code][] = $middle;
            $stations[$code] ??= [
                'station_code' => $code,
                'group_code' => (string) ($properties['N02_005g'] ?? '') ?: $code,
                'name' => $name,
                'name_normalized' => $this->names->normalize($name),
                'line_name' => (string) ($properties['N02_003'] ?? ''),
                'operator_name' => (string) ($properties['N02_004'] ?? ''),
            ];
        }

        $stations = array_values(array_map(function (array $station) use ($middles): array {
            $points = $middles[$station['station_code']];

            return [
                ...$station,
                'latitude' => round(array_sum(array_column($points, 1)) / count($points), 6),
                'longitude' => round(array_sum(array_column($points, 0)) / count($points), 6),
            ];
        }, $stations));

        return ['stations' => $stations, 'skipped' => $skipped];
    }

    /**
     * The point halfway along a LineString ([longitude, latitude]), so that
     * vertices crowded at one end of a platform do not pull it there.
     * Longitude is scaled by the cosine of the latitude to measure lengths.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{0: float, 1: float}|null
     */
    private function middle(array $geometry): ?array
    {
        $points = ($geometry['type'] ?? null) === 'LineString' ? $geometry['coordinates'] ?? [] : [];
        $points = array_values(array_filter($points, fn (mixed $point): bool => is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)));

        if ($points === []) {
            return null;
        }

        $scale = cos(deg2rad((float) $points[0][1]));
        $lengths = [];

        for ($index = 1; $index < count($points); $index++) {
            $lengths[] = hypot(((float) $points[$index][0] - (float) $points[$index - 1][0]) * $scale, (float) $points[$index][1] - (float) $points[$index - 1][1]);
        }

        $remaining = array_sum($lengths) / 2;

        foreach ($lengths as $index => $length) {
            if ($length > 0 && $remaining <= $length) {
                $ratio = $remaining / $length;
                [$from, $to] = [$points[$index], $points[$index + 1]];

                return [
                    (float) $from[0] + ((float) $to[0] - (float) $from[0]) * $ratio,
                    (float) $from[1] + ((float) $to[1] - (float) $from[1]) * $ratio,
                ];
            }

            $remaining -= $length;
        }

        // A single point, or all of them at one place.
        return [(float) $points[0][0], (float) $points[0][1]];
    }

    /**
     * The prefecture and municipality of the nearest located facility.
     *
     * @return array{prefecture_code: string, municipality_code: string}|null
     */
    private function nearestArea(float $latitude, float $longitude): ?array
    {
        $facility = MedicalFacility::query()
            ->toBase()
            ->select(['prefecture_code', 'municipality_code'])
            ->whereNotNull('municipality_code')
            ->whereBetween('latitude', [$latitude - self::NEARBY_DEGREES, $latitude + self::NEARBY_DEGREES])
            ->whereBetween('longitude', [$longitude - self::NEARBY_DEGREES, $longitude + self::NEARBY_DEGREES])
            ->orderByRaw('ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?))', [$longitude, $latitude])
            ->first();

        return $facility === null ? null : [
            'prefecture_code' => (string) $facility->prefecture_code,
            'municipality_code' => (string) $facility->municipality_code,
        ];
    }

    /**
     * The features of the UTF-8 Station GeoJSON in the downloaded zip
     * (which also holds the railway lines, and both as Shapefile and GML).
     *
     * @return list<array<string, mixed>>
     */
    private function features(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open zip \"{$zipPath}\".");
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

                if (str_contains($name, 'UTF-8/') && str_ends_with($name, '_Station.geojson')) {
                    $geoJson = json_decode((string) $zip->getFromIndex($index), true, flags: JSON_THROW_ON_ERROR);

                    return array_values($geoJson['features'] ?? []);
                }
            }

            throw new RuntimeException("No station GeoJSON in \"{$zipPath}\".");
        } finally {
            $zip->close();
        }
    }
}
