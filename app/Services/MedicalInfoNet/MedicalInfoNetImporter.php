<?php

namespace App\Services\MedicalInfoNet;

use App\Enums\InstitutionType;
use App\Models\MedicalInfoNetLocation;
use App\Models\MedicalInfoNetSchedule;
use App\Services\Address\FacilityMatchingKeys;
use App\Services\Rhb\RhbScope;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replaces medical_info_net_locations and medical_info_net_schedules with
 * the latest 医療情報ネット publication: for each facility in an in-scope
 * prefecture, its matching keys, its position, its days off and its opening
 * hours. Facilities listed with "0.0" (about 8% of the source) are kept with
 * no position, so that MedicalInfoNetLocator does not mistake a namesake for
 * them.
 */
final class MedicalInfoNetImporter
{
    private const int INSERT_CHUNK_SIZE = 1000;

    /**
     * Rows waiting to be inserted, and how many have been, per model.
     *
     * @var array<class-string<MedicalInfoNetLocation|MedicalInfoNetSchedule>, array{pending: list<array<string, mixed>>, inserted: int}>
     */
    private array $inserts = [];

    public function __construct(
        private readonly MedicalInfoNetClient $client,
        private readonly MedicalInfoNetHours $hours,
        private readonly FacilityMatchingKeys $keys,
        private readonly RhbScope $scope,
    ) {}

    /**
     * @return array{published_on: Carbon, imported: int, located: int, scheduled: int}|null null when that publication is already imported (unless $force)
     */
    public function import(bool $force = false): ?array
    {
        $latest = $this->client->latest();
        $current = MedicalInfoNetLocation::query()->max('published_on');

        if (! $force && $current !== null && Carbon::parse($current)->isSameDay($latest['published_on'])) {
            return null;
        }

        // Streamed into the tables a chunk at a time (all of Japan is ~190,000
        // facilities and ~1.3 million rows of hours, too many to hold within
        // PHP's default 128MB), inside one transaction so the API never sees
        // half-replaced tables.
        return DB::transaction(function () use ($latest): array {
            MedicalInfoNetLocation::query()->delete();
            MedicalInfoNetSchedule::query()->delete();
            $this->inserts = [];
            $now = now();
            $located = 0;
            // The IDs of the in-scope facilities, whose hours are kept.
            $sourceIds = [];

            foreach ($latest['files'] as $file) {
                foreach ($this->client->rows($this->client->download($file['url'])) as $row) {
                    $location = $this->location($row);

                    if ($location === null || ! $this->scope->includesPrefecture(substr($location['municipality_code'], 0, 2))) {
                        continue;
                    }

                    $located += $location['latitude'] !== null ? 1 : 0;
                    $closures = $this->hours->closures($row);
                    $this->insert(MedicalInfoNetLocation::class, [
                        ...$location,
                        'closures' => $closures === null ? null : json_encode($closures, JSON_UNESCAPED_UNICODE),
                        'institution_type' => $file['institution_type']->value,
                        'published_on' => $latest['published_on']->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $sourceIds[$location['source_id']] = true;

                    if ($file['institution_type'] === InstitutionType::Pharmacy) {
                        $this->insertSchedule($location['source_id'], $this->hours->fromPharmacyRow($row), $now);
                    }
                }
            }

            foreach ($latest['hours_files'] as $file) {
                foreach ($this->facilityRows($this->client->rows($this->client->download($file['url']))) as $sourceId => $rows) {
                    if (isset($sourceIds[$sourceId])) {
                        $this->insertSchedule($sourceId, $this->hours->fromDepartmentRows($rows), $now);
                    }
                }
            }

            return [
                'published_on' => $latest['published_on'],
                'imported' => $this->flush(MedicalInfoNetLocation::class),
                'located' => $located,
                'scheduled' => $this->flush(MedicalInfoNetSchedule::class),
            ];
        });
    }

    /**
     * The rows of a 診療科・診療時間票, one facility's at a time. They are
     * published grouped by facility; an ID turning up again later would
     * mean that is no longer so, and the import fails rather than keep only
     * part of its hours.
     *
     * @param  iterable<array<string, string>>  $rows
     * @return Generator<string, list<array<string, string>>>
     */
    private function facilityRows(iterable $rows): Generator
    {
        $seen = [];
        $sourceId = null;
        $group = [];

        foreach ($rows as $row) {
            if ($row['ID'] !== $sourceId) {
                if ($sourceId !== null) {
                    yield $sourceId => $group;
                }

                if (isset($seen[$row['ID']])) {
                    throw new RuntimeException("The hours of 医療情報ネット facility {$row['ID']} are not listed together.");
                }

                $seen[$row['ID']] = true;
                $sourceId = $row['ID'];
                $group = [];
            }

            $group[] = $row;
        }

        if ($sourceId !== null) {
            yield $sourceId => $group;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $schedule
     */
    private function insertSchedule(string $sourceId, array $schedule, Carbon $now): void
    {
        if ($schedule !== []) {
            $this->insert(MedicalInfoNetSchedule::class, [
                'source_id' => $sourceId,
                'schedules' => json_encode($schedule, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  class-string<MedicalInfoNetLocation|MedicalInfoNetSchedule>  $model
     * @param  array<string, mixed>  $row
     */
    private function insert(string $model, array $row): void
    {
        $this->inserts[$model] ??= ['pending' => [], 'inserted' => 0];
        $this->inserts[$model]['pending'][] = $row;

        if (count($this->inserts[$model]['pending']) === self::INSERT_CHUNK_SIZE) {
            $this->flush($model);
        }
    }

    /**
     * Inserts the model's pending rows and returns how many have been inserted in all.
     *
     * @param  class-string<MedicalInfoNetLocation|MedicalInfoNetSchedule>  $model
     */
    private function flush(string $model): int
    {
        $this->inserts[$model] ??= ['pending' => [], 'inserted' => 0];
        $model::query()->insert($this->inserts[$model]['pending']);
        $this->inserts[$model]['inserted'] += count($this->inserts[$model]['pending']);
        $this->inserts[$model]['pending'] = [];

        return $this->inserts[$model]['inserted'];
    }

    /**
     * Pharmacies call the name column 名称, the others 正式名称.
     *
     * @param  array<string, string>  $row
     * @return array{source_id: string, municipality_code: string, name_key: string, address_key: string, latitude: ?float, longitude: ?float}|null
     */
    private function location(array $row): ?array
    {
        $latitude = (float) ($row['所在地座標（緯度）'] ?? 0);
        $longitude = (float) ($row['所在地座標（経度）'] ?? 0);
        $name = $row['正式名称'] ?? $row['名称'] ?? '';
        $prefectureCode = $row['都道府県コード'] ?? '';
        $cityCode = $row['市区町村コード'] ?? '';

        $sourceId = $row['ID'] ?? '';

        if ($sourceId === '' || $name === '' || $prefectureCode === '' || $cityCode === '') {
            return null;
        }

        $hasPosition = $latitude !== 0.0 && $longitude !== 0.0;

        return [
            'source_id' => $sourceId,
            'municipality_code' => str_pad($prefectureCode, 2, '0', STR_PAD_LEFT).str_pad($cityCode, 3, '0', STR_PAD_LEFT),
            'name_key' => $this->keys->name($name),
            'address_key' => $this->keys->address($row['所在地'] ?? ''),
            'latitude' => $hasPosition ? $latitude : null,
            'longitude' => $hasPosition ? $longitude : null,
        ];
    }
}
