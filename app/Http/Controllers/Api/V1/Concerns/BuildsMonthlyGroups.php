<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

trait BuildsMonthlyGroups
{
    /**
     * One group per month from $from's month through $to's, oldest first, so
     * months with nothing still appear (as 0) and charts have no gaps.
     *
     * @param  Collection<array-key, mixed>  $counts  counts keyed by "YYYY-MM"
     * @return list<array{key: string, label: string, count: int}>
     */
    private function monthlyGroups(Collection $counts, string $from, string $to): array
    {
        $groups = [];
        $month = Carbon::parse($from)->startOfMonth();
        $last = Carbon::parse($to)->startOfMonth();

        while ($month->lte($last)) {
            $key = $month->format('Y-m');
            $groups[] = [
                'key' => $key,
                'label' => $month->format('Y年n月'),
                'count' => (int) $counts->get($key, 0),
            ];
            $month->addMonth();
        }

        return $groups;
    }
}
