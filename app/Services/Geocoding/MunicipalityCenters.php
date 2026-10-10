<?php

namespace App\Services\Geocoding;

use App\Enums\GeocodeLevel;
use App\Enums\Prefecture;
use App\Models\MedicalFacility;

/**
 * Where facilities are, for checking that coordinates from another source
 * are plausible: the distance between two points, and where each
 * municipality's 番地-precise facilities are on average (the check for a
 * facility that has no location of its own).
 */
final class MunicipalityCenters
{
    private const int EARTH_RADIUS_METERS = 6_371_000;

    /**
     * @return array<string, array{float, float}> municipality code => [latitude, longitude]
     */
    public function of(Prefecture $prefecture): array
    {
        $centers = [];

        foreach (MedicalFacility::query()
            ->where('prefecture_code', $prefecture->value)
            // 地番 too: towns without 住居表示 have only those.
            ->whereIn('geocode_level', [GeocodeLevel::Residence, GeocodeLevel::Block, GeocodeLevel::Parcel, GeocodeLevel::ParcelBase])
            ->whereNotNull('municipality_code')
            ->groupBy('municipality_code')
            ->havingRaw('COUNT(*) >= 5')
            ->selectRaw('municipality_code, AVG(latitude) AS latitude, AVG(longitude) AS longitude')
            ->toBase()
            ->get() as $row) {
            $centers[(string) $row->municipality_code] = [(float) $row->latitude, (float) $row->longitude];
        }

        return $centers;
    }

    /**
     * Great-circle distance in meters.
     *
     * @param  array{float, float}  $from
     * @param  array{float, float}  $to
     */
    public function distance(array $from, array $to): float
    {
        [$latitude1, $longitude1] = array_map('deg2rad', $from);
        [$latitude2, $longitude2] = array_map('deg2rad', $to);
        $haversine = sin(($latitude2 - $latitude1) / 2) ** 2
            + cos($latitude1) * cos($latitude2) * sin(($longitude2 - $longitude1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(sqrt($haversine));
    }
}
