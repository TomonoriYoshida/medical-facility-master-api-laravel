<?php

namespace App\Services\Geocoding;

use App\Enums\GeocodeLevel;
use App\Enums\Prefecture;
use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use App\Services\Address\FacilityMatchingKeys;

/**
 * Replaces a 町丁目-level (or missing) location with the 医療情報ネット's
 * coordinates for the same facility. Measured on all of Japan (2026-10-01),
 * those agree with the Address Base Registry's 住居/地番 points within
 * 10-40m (median), so they are the better of the two whenever the registry
 * only reaches the 町丁目. They are never used over a 番地-level location:
 * where the two disagree by over 1km, the 医療情報ネット is the one off
 * about twice as often.
 *
 * A facility is matched only when exactly one 医療情報ネット facility of the
 * same type and municipality has its name (or, failing that, its address and
 * a name containing or contained in its own) -- counting the facilities it
 * lists without coordinates, so that a namesake with coordinates is not
 * mistaken for one without -- and the coordinates are kept only when plausible: within
 * 2km of the 町丁目 point, or with no location at all, within 30km of where
 * the municipality's other facilities are.
 */
final class MedicalInfoNetLocator
{
    private const int TOWN_TOLERANCE_METERS = 2_000;

    private const int MUNICIPALITY_TOLERANCE_METERS = 30_000;

    private const int EARTH_RADIUS_METERS = 6_371_000;

    public function __construct(private readonly FacilityMatchingKeys $keys) {}

    /**
     * @param  array<int, array{institution_type: int, municipality_code: ?string, name: string, address: string}>  $facilities  facility id => attributes, all in $prefecture
     * @param  array<int, GeocodeResult|null>  $results  the registry's locations for those facilities
     * @return array<int, GeocodeResult|null>
     */
    public function refine(Prefecture $prefecture, array $facilities, array $results): array
    {
        $candidates = array_filter(
            $facilities,
            fn (array $facility, int $id): bool => $facility['municipality_code'] !== null
                && ($results[$id] === null || $results[$id]->level === GeocodeLevel::Town),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($candidates === []) {
            return $results;
        }

        [$byName, $byAddress] = $this->index($prefecture);
        $centers = null;

        foreach ($candidates as $id => $facility) {
            $location = $this->match($facility, $byName, $byAddress);

            if ($location === null) {
                continue;
            }

            $reference = $results[$id] !== null
                ? [[$results[$id]->latitude, $results[$id]->longitude], self::TOWN_TOLERANCE_METERS]
                : [($centers ??= $this->municipalityCenters($prefecture))[$facility['municipality_code']] ?? null, self::MUNICIPALITY_TOLERANCE_METERS];

            if ($reference[0] !== null && $this->distance($location, $reference[0]) <= $reference[1]) {
                $results[$id] = new GeocodeResult(GeocodeLevel::MedicalInfoNet, $location[0], $location[1]);
            }
        }

        return $results;
    }

    /**
     * @param  array{institution_type: int, municipality_code: ?string, name: string, address: string}  $facility
     * @param  array<string, list<array{address: string, name: string, position: array{float, float}|null}>>  $byName
     * @param  array<string, list<array{address: string, name: string, position: array{float, float}|null}>>  $byAddress
     * @return array{float, float}|null
     */
    private function match(array $facility, array $byName, array $byAddress): ?array
    {
        $scope = $facility['institution_type'].'|'.$facility['municipality_code'].'|';
        $name = $this->keys->name($facility['name']);
        $address = $this->keys->address($facility['address']);

        $matches = $byName[$scope.$name] ?? [];

        if (count($matches) > 1) {
            $matches = array_values(array_filter($matches, fn (array $entry): bool => $entry['address'] === $address));
        }

        // Same address, and a name that is clearly the same facility: one
        // contains the other ("イムス札幌病院" / "札幌イムス札幌病院分院" do not;
        // "イムス札幌病院" / "イムス札幌病院附属" do).
        if ($matches === [] && mb_strlen($name) >= 3) {
            $matches = array_values(array_filter(
                $byAddress[$scope.$address] ?? [],
                fn (array $entry): bool => mb_strlen($entry['name']) >= 3
                    && (str_contains($entry['name'], $name) || str_contains($name, $entry['name'])),
            ));
        }

        // The match may be a facility the 医療情報ネット lists without
        // coordinates; it is still the match, so nothing is used.
        return count($matches) === 1 ? $matches[0]['position'] : null;
    }

    /**
     * @return array{0: array<string, list<array{address: string, name: string, position: array{float, float}|null}>>, 1: array<string, list<array{address: string, name: string, position: array{float, float}|null}>>}
     */
    private function index(Prefecture $prefecture): array
    {
        $byName = [];
        $byAddress = [];

        foreach (MedicalInfoNetLocation::query()->where('municipality_code', 'like', $prefecture->value.'%')->toBase()->cursor() as $row) {
            $scope = $row->institution_type.'|'.$row->municipality_code.'|';
            $entry = [
                'address' => (string) $row->address_key,
                'name' => (string) $row->name_key,
                'position' => $row->latitude === null || $row->longitude === null ? null : [(float) $row->latitude, (float) $row->longitude],
            ];
            $byName[$scope.$row->name_key][] = $entry;
            $byAddress[$scope.$row->address_key][] = $entry;
        }

        return [$byName, $byAddress];
    }

    /**
     * Where each municipality's 番地-precise facilities (住居, 街区, 地番) are on average, as
     * the check for a facility that has no location of its own.
     *
     * @return array<string, array{float, float}>
     */
    private function municipalityCenters(Prefecture $prefecture): array
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
    private function distance(array $from, array $to): float
    {
        [$latitude1, $longitude1] = array_map('deg2rad', $from);
        [$latitude2, $longitude2] = array_map('deg2rad', $to);
        $haversine = sin(($latitude2 - $latitude1) / 2) ** 2
            + cos($latitude1) * cos($latitude2) * sin(($longitude2 - $longitude1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(sqrt($haversine));
    }
}
