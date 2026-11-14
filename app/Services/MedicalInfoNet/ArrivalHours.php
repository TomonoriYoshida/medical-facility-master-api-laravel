<?php

namespace App\Services\MedicalInfoNet;

use App\Models\MedicalFacilityOpeningPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * For facilities reached at a given time each (the list's `travel` and
 * `wait`), the opening hours that count: the range open when they arrive,
 * or else the first one starting within $waitMinutes after it. The same
 * rules as the list's filter: medical_facility_opening_periods on the day's
 * number (a public holiday's own) and in its week of the month. A range
 * running to midnight joins one starting at 00:00 the next day, so
 * 18:00-02:00 is one range. Each arrival's day and the next are read: a
 * wait of up to an hour, or a range past midnight, reaches into the next
 * day but no further.
 *
 * With the arrival at the searched time itself and no wait, this is also
 * how a plain `open_at` search finds when each facility closes.
 */
final class ArrivalHours
{
    /**
     * @param  array<int, Carbon>  $arrivals  facility id => when it is reached
     * @return array<int, array{opens_at: ?Carbon, open_until: Carbon}> facility id => the range that counts
     *                                                                  (opens_at null when open on arrival), for
     *                                                                  the facilities with one
     */
    public function at(array $arrivals, int $waitMinutes): array
    {
        if ($arrivals === []) {
            return [];
        }

        $days = [];

        foreach ($arrivals as $arrival) {
            $start = $arrival->copy()->setTimezone('Asia/Tokyo')->startOfDay();

            foreach ([$start, $start->copy()->addDay()] as $day) {
                $days[$day->toDateString()] ??= ['start' => $day, 'moment' => OpeningMoment::at($day)];
            }
        }

        $periodsByFacility = MedicalFacilityOpeningPeriod::query()
            ->whereIn('medical_facility_id', array_keys($arrivals))
            ->whereIn('day', array_values(array_unique(array_map(fn (array $day): int => $day['moment']->day, $days))))
            ->get()
            ->groupBy('medical_facility_id');

        $hours = [];

        foreach ($arrivals as $facilityId => $arrival) {
            $start = $arrival->copy()->setTimezone('Asia/Tokyo')->startOfDay();
            $ranges = $this->ranges(
                $periodsByFacility->get($facilityId, collect()),
                [$days[$start->toDateString()], $days[$start->copy()->addDay()->toDateString()]],
            );
            $latestStart = $arrival->copy()->addMinutes($waitMinutes);

            foreach ($ranges as [$opens, $closes]) {
                if ($closes > $arrival && $opens <= $latestStart) {
                    $hours[$facilityId] = ['opens_at' => $opens > $arrival ? $opens : null, 'open_until' => $closes];

                    break;
                }
            }
        }

        return $hours;
    }

    /**
     * The facility's ranges on the given days as times, in order, with
     * touching ones joined.
     *
     * @param  Collection<int, MedicalFacilityOpeningPeriod>  $periods
     * @param  list<array{start: Carbon, moment: OpeningMoment}>  $days
     * @return list<array{Carbon, Carbon}>
     */
    private function ranges(Collection $periods, array $days): array
    {
        $ranges = [];

        foreach ($days as ['start' => $start, 'moment' => $moment]) {
            foreach ($periods as $period) {
                if ($period->day === $moment->day && ($period->weeks & $moment->weekBit) !== 0) {
                    $ranges[] = [$this->timeOn($start, $period->opens), $this->timeOn($start, $period->closes)];
                }
            }
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $joined = [];

        foreach ($ranges as [$opens, $closes]) {
            $last = count($joined) - 1;

            if ($last >= 0 && $opens <= $joined[$last][1]) {
                $joined[$last][1] = max($joined[$last][1], $closes);
            } else {
                $joined[] = [$opens, $closes];
            }
        }

        return $joined;
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
