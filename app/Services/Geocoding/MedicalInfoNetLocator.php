<?php

namespace App\Services\Geocoding;

use App\Enums\GeocodeLevel;
use App\Enums\Prefecture;
use App\Models\MedicalInfoNetLocation;
use App\Services\MedicalInfoNet\MedicalInfoNetMatcher;

/**
 * Replaces a 町丁目-level (or missing) location with the 医療情報ネット's
 * coordinates for the same facility. Measured on all of Japan (2026-10-01),
 * those agree with the Address Base Registry's 住居/地番 points within
 * 10-40m (median), so they are the better of the two whenever the registry
 * only reaches the 町丁目. They are never used over a 番地-level location:
 * where the two disagree by over 1km, the 医療情報ネット is the one off
 * about twice as often.
 *
 * A facility is matched as MedicalInfoNetMatcher does -- a match the
 * 医療情報ネット lists without coordinates is still the match, so nothing is
 * used -- and the coordinates are kept only when plausible: within 2km of the
 * 町丁目 point, or with no location at all, within 30km of where the
 * municipality's other facilities are.
 */
final class MedicalInfoNetLocator
{
    private const int TOWN_TOLERANCE_METERS = 2_000;

    private const int MUNICIPALITY_TOLERANCE_METERS = 30_000;

    public function __construct(
        private readonly MedicalInfoNetMatcher $matcher,
        private readonly MunicipalityCenters $municipalityCenters,
    ) {}

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

        $index = $this->matcher->index(
            MedicalInfoNetLocation::query()
                ->where('municipality_code', 'like', $prefecture->value.'%')
                ->select(['id', 'institution_type', 'municipality_code', 'name_key', 'address_key', 'latitude', 'longitude'])
                ->toBase()
                ->cursor(),
        );
        $centers = null;

        foreach ($candidates as $id => $facility) {
            $location = $this->matcher->match($facility, $index)['position'] ?? null;

            if ($location === null) {
                continue;
            }

            $reference = $results[$id] !== null
                ? [[$results[$id]->latitude, $results[$id]->longitude], self::TOWN_TOLERANCE_METERS]
                : [($centers ??= $this->municipalityCenters->of($prefecture))[$facility['municipality_code']] ?? null, self::MUNICIPALITY_TOLERANCE_METERS];

            if ($reference[0] !== null && $this->municipalityCenters->distance($location, $reference[0]) <= $reference[1]) {
                $results[$id] = new GeocodeResult(GeocodeLevel::MedicalInfoNet, $location[0], $location[1]);
            }
        }

        return $results;
    }
}
