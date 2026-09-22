<?php

namespace App\Services\Mhlw\Import;

/**
 * Shared weekday-key source of truth, reused by ClosureScheduleParser and
 * TimeSlotParser so their JSON output keys can never drift apart.
 */
enum Weekday: string
{
    case Monday = 'mon';
    case Tuesday = 'tue';
    case Wednesday = 'wed';
    case Thursday = 'thu';
    case Friday = 'fri';
    case Saturday = 'sat';
    case Sunday = 'sun';

    /**
     * @return list<self>
     */
    public static function week(): array
    {
        return [
            self::Monday,
            self::Tuesday,
            self::Wednesday,
            self::Thursday,
            self::Friday,
            self::Saturday,
            self::Sunday,
        ];
    }
}
