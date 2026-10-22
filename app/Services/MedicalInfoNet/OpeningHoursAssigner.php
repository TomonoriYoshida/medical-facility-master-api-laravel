<?php

namespace App\Services\MedicalInfoNet;

use App\Models\MedicalFacility;
use App\Models\MedicalFacilityOpeningPeriod;
use App\Models\MedicalInfoNetLocation;
use App\Models\MedicalInfoNetSchedule;
use App\Services\Rhb\RhbScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Matches every facility with the 医療情報ネット (MedicalInfoNetMatcher) and
 * stores the match (medical_facilities.medical_info_net_id, without moving
 * updated_at), the website and name reading it lists (name_kana and
 * website_url, part of the served facility, so updated_at moves when they
 * change) and the ranges it is open in (medical_facility_opening_periods,
 * see OpeningPeriods), so that the opening hours endpoint and the list
 * API's open_at agree. Facilities are written a chunk at a time, each chunk
 * in one transaction.
 *
 * The inputs change only when facilities are imported (updated_at moves on
 * a new facility, a change or a closure) or the 医療情報ネット is
 * re-imported, so an unchanged fingerprint of those skips the run. The
 * fingerprint is the one taken before the run: a run that moves updated_at
 * itself (a new website or name reading) is followed by one more the next
 * morning, rather than a facility changed during the run being missed.
 * Facilities listed in medical_info_net.without_outpatients keep their
 * match but get no periods.
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
        $now = now();

        foreach ($this->scope->prefectures() as $prefecture) {
            /** @var Collection<int, stdClass> $locations */
            $locations = MedicalInfoNetLocation::query()
                ->where('municipality_code', 'like', $prefecture->value.'%')
                ->select(['id', 'source_id', 'institution_type', 'municipality_code', 'name_key', 'address_key', 'latitude', 'longitude', 'closures', 'name_kana', 'website_url'])
                ->toBase()
                ->get()
                ->keyBy('id');
            $index = $this->matcher->index($locations);

            MedicalFacility::query()
                ->where('prefecture_code', $prefecture->value)
                ->select(['id', 'institution_type', 'municipality_code', 'name', 'address', 'medical_info_net_id', 'name_kana', 'website_url'])
                ->toBase()
                ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($locations, $index, $now, &$totals): void {
                    $counts = $this->assignChunk($facilities, $locations, $index, $now);
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
    private function assignChunk(Collection $facilities, Collection $locations, array $index, Carbon $now): array
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
        $withoutOutpatients = config('medical_info_net.without_outpatients', []);
        $periods = [];
        $changes = [];

        foreach ($facilities as $facility) {
            $location = $matches[(int) $facility->id];
            $sourceId = $location?->source_id;
            $served = ['name_kana' => $location?->name_kana, 'website_url' => $location?->website_url];
            $servedChanged = $served !== ['name_kana' => $facility->name_kana, 'website_url' => $facility->website_url];

            if ($sourceId !== $facility->medical_info_net_id || $servedChanged) {
                $changes[(int) $facility->id] = ['medical_info_net_id' => $sourceId, ...$served, 'moves_updated_at' => $servedChanged];
            }

            if ($location === null || ! $schedules->has($sourceId) || in_array($sourceId, $withoutOutpatients, true)) {
                continue;
            }

            foreach ($this->openingPeriods->fromSchedules(
                json_decode((string) $schedules->get($sourceId), true),
                $location->closures === null ? null : json_decode((string) $location->closures, true),
            ) as $period) {
                $periods[] = ['medical_facility_id' => (int) $facility->id, ...$period];
            }
        }

        DB::transaction(function () use ($facilities, $periods, $changes, $now): void {
            MedicalFacilityOpeningPeriod::query()->whereIn('medical_facility_id', $facilities->pluck('id'))->delete();

            foreach (array_chunk($periods, self::CHUNK_SIZE) as $chunk) {
                MedicalFacilityOpeningPeriod::query()->insert($chunk);
            }

            $this->updateFacilities($changes, $now);
        });

        return ['matched' => count($sourceIds), 'periods' => count($periods)];
    }

    /**
     * One UPDATE for the chunk's changed facilities; updated_at moves only
     * for those whose website or name reading changed, to the run's time.
     *
     * @param  array<int, array{medical_info_net_id: string|null, name_kana: string|null, website_url: string|null, moves_updated_at: bool}>  $changes  by facility id
     */
    private function updateFacilities(array $changes, Carbon $now): void
    {
        if ($changes === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($changes), 'WHEN ? THEN ?'));
        $placeholders = implode(',', array_fill(0, count($changes), '?'));
        $bindings = [];

        foreach (['medical_info_net_id', 'name_kana', 'website_url'] as $column) {
            foreach ($changes as $id => $change) {
                array_push($bindings, $id, $change[$column]);
            }
        }

        $moved = array_keys(array_filter($changes, fn (array $change): bool => $change['moves_updated_at']));
        $updatedAt = $moved === []
            ? 'updated_at'
            : 'CASE WHEN id IN ('.implode(',', array_fill(0, count($moved), '?')).') THEN ? ELSE updated_at END';

        DB::update(
            "UPDATE medical_facilities SET medical_info_net_id = CASE id {$cases} END, name_kana = CASE id {$cases} END, website_url = CASE id {$cases} END, updated_at = {$updatedAt} WHERE id IN ({$placeholders})",
            [...$bindings, ...($moved === [] ? [] : [...$moved, $now]), ...array_keys($changes)],
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
            implode(',', config('medical_info_net.without_outpatients', [])),
        ]);
    }
}
