<?php

namespace App\Services\MedicalInfoNet;

/**
 * A rough estimate of how long it takes to reach a facility, from the
 * straight-line distance alone (no routing service: no cost, and the
 * visitor's position is not sent anywhere else). Roads wind, so the distance
 * is stretched by 1.3 before dividing by the speed: on foot the 80 m/min
 * (4.8 km/h) Japanese property listings use, by car an urban average of
 * 20 km/h.
 *
 * Minutes are meters x 1.3 x 60 / (meters per hour), written as
 * meters x 78 / (meters per hour) in both PHP and SQL, so that the two round
 * up to the same minute (the list filters in SQL and labels in PHP).
 */
final class TravelTime
{
    /** 1.3 (the detour) x 60 (minutes per hour). */
    private const int STRETCH_MINUTES_PER_HOUR = 78;

    /** @var array<string, int> mode => meters per hour */
    public const array METERS_PER_HOUR = ['walk' => 4800, 'car' => 20000];

    /**
     * Whole minutes, rounded up, to cover $meters by $mode.
     */
    public static function minutes(string $mode, float $meters): int
    {
        return (int) ceil($meters * self::STRETCH_MINUTES_PER_HOUR / self::METERS_PER_HOUR[$mode]);
    }

    /**
     * The same in SQL, for a distance expression in meters.
     */
    public static function minutesSql(string $mode, string $meters): string
    {
        return sprintf('CEIL((%s) * %d / %d)', $meters, self::STRETCH_MINUTES_PER_HOUR, self::METERS_PER_HOUR[$mode]);
    }
}
