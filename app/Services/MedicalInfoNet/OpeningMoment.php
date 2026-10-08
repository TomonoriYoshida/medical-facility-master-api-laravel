<?php

namespace App\Services\MedicalInfoNet;

use App\Models\PublicHoliday;
use Illuminate\Support\Carbon;

/**
 * A moment in Japan as medical_facility_opening_periods stores it: the day
 * number (ISO weekday, or OpeningPeriods::HOLIDAY on a public holiday), the
 * time of day, and the bit of its week of the month in `weeks`.
 */
final readonly class OpeningMoment
{
    public function __construct(
        public int $day,
        public string $time,
        public int $weekBit,
    ) {}

    public static function at(Carbon $at): self
    {
        $at = $at->copy()->setTimezone('Asia/Tokyo');

        return new self(
            PublicHoliday::isHoliday($at) ? OpeningPeriods::HOLIDAY : $at->isoWeekday(),
            $at->format('H:i:s'),
            1 << intdiv($at->day - 1, 7),
        );
    }
}
