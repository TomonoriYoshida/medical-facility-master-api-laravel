<?php

namespace App\Services\MedicalInfoNet;

use App\Models\MedicalFacility;
use App\Models\MedicalFacilityOpeningPeriod;
use App\Models\MedicalInfoNetLocation;
use App\Models\MedicalInfoNetSchedule;
use App\Services\Rhb\RhbScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Matches every facility with the 医療情報ネット (MedicalInfoNetMatcher) and
 * stores the match (medical_facilities.medical_info_net_id, without moving
 * updated_at) and the ranges it is open in (medical_facility_opening_periods,
 * see OpeningPeriods), so that the opening hours endpoint and the list
 * API's open_at agree. Facilities are written a chunk at a time, each chunk
 * in one transaction.
 *
 * The inputs change only when facilities are imported (updated_at moves on
 * a new facility, a change or a closure) or the 医療情報ネット is
 * re-imported, so an unchanged fingerprint of those skips the run.
 */
final class OpeningHoursAssigner
{
    public const string FINGERPRINT_CACHE_KEY = 'opening-hours:fingerprint';

    private const int CHUNK_SIZE = 1000;

    public function __construct(
        private readonly MedicalInfoNetMatcher $matcher,
        private readonly OpeningPeriods $openingPeriods,
        private readonly RhbScope $scope,
    ) {}

    /**
     * @return array{facilities: int, matched: int, periods: int}|null null when nothing changed since the last run (unless $force)
     */
    public function assign(bool $force = false): ?array
    {
        $fingerprint = $this->fingerprint();

        if (! $force && Cache::get(self::FINGERPRINT_CACHE_KEY) === $fingerprint) {
            return null;
        }

        $totals = ['facilities' => 0, 'matched' => 0, 'periods' => 0];

        foreach ($this->scope->prefectures() as $prefecture) {
            /** @var Collection<int, stdClass> $locations */
            $locations = MedicalInfoNetLocation::query()
                ->where('municipality_code', 'like', $prefecture->value.'%')
                ->select(['id', 'source_id', 'institution_type', 'municipality_code', 'name_key', 'address_key', 'latitude', 'longitude', 'closures'])
                ->toBase()
                ->get()
                ->keyBy('id');
            $index = $this->matcher->index($locations);

            MedicalFacility::query()
                ->where('prefecture_code', $prefecture->value)
                ->select(['id', 'institution_type', 'municipality_code', 'name', 'address', 'medical_info_net_id'])
                ->toBase()
                ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($locations, $index, &$totals): void {
                    $counts = $this->assignChunk($facilities, $locations, $index);
                    $totals['facilities'] += $facilities->count();
                    $totals['matched'] += $counts['matched'];
                    $totals['periods'] += $counts['periods'];
                });
        }

        Cache::forever(self::FINGERPRINT_CACHE_KEY, $fingerprint);

        return $totals;
    }

    /**
     * @param  Collection<int, stdClass>  $facilities
     * @param  Collection<int, stdClass>  $locations  by id
     * @param  array{0: array<string, list<array{id: int, address: string, name: string, position: array{float, float}|null}>>, 1: array<string, list<array{id: int, address: string, name: string, position: array{float, float}|null}>>}  $index
     * @return array{matched: int, periods: int}
     */
    private function assignChunk(Collection $facilities, Collection $locations, array $index): array
    {
        /** @var array<int, stdClass|null> $matches facility id => its 医療情報ネット location */
        $matches = [];

        foreach ($facilities as $facility) {
            $match = $facility->municipality_code === null ? null : $this->matcher->match([
                'institution_type' => (int) $facility->institution_type,
                'municipality_code' => (string) $facility->municipality_code,
                'name' => (string) $facility->name,
                'address' => (string) $facility->address,
            ], $index);
            $matches[(int) $facility->id] = $match === null ? null : $locations->get($match['id']);
        }

        $sourceIds = array_values(array_filter(array_map(fn (?stdClass $location): ?string => $location?->source_id, $matches)));
        $schedules = MedicalInfoNetSchedule::query()
            ->whereIn('source_id', $sourceIds)
            ->toBase()
            ->pluck('schedules', 'source_id');
        $periods = [];
        $changedIds = [];

        foreach ($facilities as $facility) {
            $location = $matches[(int) $facility->id];
            $sourceId = $location?->source_id;

            if ($sourceId !== $facility->medical_info_net_id) {
                $changedIds[(int) $facility->id] = $sourceId;
            }

            if ($location === null || ! $schedules->has($sourceId)) {
                continue;
            }

            foreach ($this->openingPeriods->fromSchedules(
                json_decode((string) $schedules->get($sourceId), true),
                $location->closures === null ? null : json_decode((string) $location->closures, true),
            ) as $period) {
                $periods[] = ['medical_facility_id' => (int) $facility->id, ...$period];
            }
        }

        DB::transaction(function () use ($facilities, $periods, $changedIds): void {
            MedicalFacilityOpeningPeriod::query()->whereIn('medical_facility_id', $facilities->pluck('id'))->delete();

            foreach (array_chunk($periods, self::CHUNK_SIZE) as $chunk) {
                MedicalFacilityOpeningPeriod::query()->insert($chunk);
            }

            $this->updateMatches($changedIds);
        });

        return ['matched' => count($sourceIds), 'periods' => count($periods)];
    }

    /**
     * One UPDATE for the chunk's changed matches; updated_at stays as it is.
     *
     * @param  array<int, string|null>  $changedIds  facility id => 医療情報ネット ID
     */
    private function updateMatches(array $changedIds): void
    {
        if ($changedIds === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($changedIds), 'WHEN ? THEN ?'));
        $placeholders = implode(',', array_fill(0, count($changedIds), '?'));
        $bindings = [];

        foreach ($changedIds as $id => $sourceId) {
            array_push($bindings, $id, $sourceId);
        }

        DB::update(
            "UPDATE medical_facilities SET medical_info_net_id = CASE id {$cases} END WHERE id IN ({$placeholders})",
            [...$bindings, ...array_keys($changedIds)],
        );
    }

    private function fingerprint(): string
    {
        return implode('|', [
            MedicalFacility::query()->count(),
            (string) MedicalFacility::query()->max('updated_at'),
            MedicalInfoNetLocation::query()->count(),
            (string) MedicalInfoNetLocation::query()->max('id'),
            (string) MedicalInfoNetSchedule::query()->max('id'),
        ]);
    }
}
