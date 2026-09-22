<?php

namespace App\Services\Mhlw\Import;

/**
 * Column offsets for one "closure schedule" block within an MHLW facility
 * CSV row. Hospital/clinic/dental/maternity_home share an identical
 * 44-column shape (see standard()); pharmacy's is a genuinely different
 * 53-column shape with an extra "営業日" (open-weekdays) block and its own
 * holiday sub-column inside the weekly-closure block (see pharmacy()).
 */
final readonly class ClosureScheduleConfig
{
    public function __construct(
        public int $weeklyStart,
        public int $monthlyPatternStart,
        public int $holidayColumnIndex,
        public int $otherClosedDatesColumnIndex,
        public ?int $openWeekdaysStart = null,
        public ?int $weeklyHolidaySubColumnIndex = null,
    ) {}

    /**
     * Hospital/clinic/dental/maternity_home: 7 weekly-flag columns, then
     * 5 weeks x 7 days of monthly-pattern columns, then a standalone
     * holiday flag, then the free-text "other closed dates" column.
     */
    public static function standard(int $startIndex = 13): self
    {
        $monthlyPatternStart = $startIndex + 7;
        $holidayColumnIndex = $monthlyPatternStart + (5 * 7);

        return new self(
            weeklyStart: $startIndex,
            monthlyPatternStart: $monthlyPatternStart,
            holidayColumnIndex: $holidayColumnIndex,
            otherClosedDatesColumnIndex: $holidayColumnIndex + 1,
        );
    }

    /**
     * Pharmacy: an 8-column "営業日" (open weekdays, includes a holiday
     * sub-column) block precedes the 8-column "定期閉店毎週" (weekly
     * closure, also includes a holiday sub-column) block, then 5 weeks x 7
     * days of monthly-pattern columns (no holiday sub-column there), then
     * a standalone holiday flag, then the free-text closure-dates column.
     */
    public static function pharmacy(): self
    {
        return new self(
            weeklyStart: 19,
            monthlyPatternStart: 27,
            holidayColumnIndex: 62,
            otherClosedDatesColumnIndex: 63,
            openWeekdaysStart: 11,
            weeklyHolidaySubColumnIndex: 26,
        );
    }
}
