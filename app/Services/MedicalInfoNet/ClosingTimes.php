<?php

namespace App\Services\MedicalInfoNet;

use App\Models\MedicalFacilityOpeningPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * When facilities open at a moment stop being open, by the same rules the
 * list's `open_at` filter uses. A range running to midnight continues into
 * one opening at 00:00 the next day (how OpeningPeriods stores hours past
 * midnight), so 18:00-02:00 ends at 02:00, not at midnight.
 */
final class ClosingTimes
{
    private const string MIDNIGHT = '00:00:00';

    private const string END_OF_DAY = '24:00:00';

    /**
     * @param  array<int, int>  $facilityIds
     * @return array<int, Carbon> facility id => closing time in Japan, for the facilities open at $at
     */
    public function at(Carbon $at, array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }

        $at = $at->copy()->setTimezone('Asia/Tokyo');
        $tomorrow = $at->copy()->addDay()->startOfDay();
        $now = OpeningMoment::at($at);
        $next = OpeningMoment::at($tomorrow);

        $periodsByFacility = MedicalFacilityOpeningPeriod::query()
            ->whereIn('medical_facility_id', $facilityIds)
            ->whereIn('day', array_unique([$now->day, $next->day]))
            ->get()
            ->groupBy('medical_facility_id');

        $closingTimes = [];

        foreach ($periodsByFacility as $facilityId => $periods) {
            $current = $periods->first(fn (MedicalFacilityOpeningPeriod $period): bool => $this->covers($period, $now));

            if ($current === null) {
                continue;
            }

            $continued = $current->closes === self::END_OF_DAY ? $this->continuation($periods, $next) : null;

            $closingTimes[$facilityId] = $continued === null
                ? $this->timeOn($at->copy()->startOfDay(), $current->closes)
                : $this->timeOn($tomorrow, $continued->closes);
        }

        return $closingTimes;
    }

    private function covers(MedicalFacilityOpeningPeriod $period, OpeningMoment $moment): bool
    {
        return $period->day === $moment->day
            && $period->opens <= $moment->time
            && $period->closes > $moment->time
            && ($period->weeks & $moment->weekBit) !== 0;
    }

    /**
     * @param  Collection<int, MedicalFacilityOpeningPeriod>  $periods
     */
    private function continuation(Collection $periods, OpeningMoment $next): ?MedicalFacilityOpeningPeriod
    {
        return $periods->first(fn (MedicalFacilityOpeningPeriod $period): bool => $period->day === $next->day
            && $period->opens === self::MIDNIGHT
            && ($period->weeks & $next->weekBit) !== 0);
    }

    /**
     * "24:00:00" is the next midnight.
     */
    private function timeOn(Carbon $startOfDay, string $time): Carbon
    {
        [$hours, $minutes, $seconds] = array_map(intval(...), explode(':', $time));

        return $startOfDay->copy()->addSeconds($hours * 3600 + $minutes * 60 + $seconds);
    }
}
