<?php

namespace App\Services\Geocoding;

use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Enums\Prefecture;
use App\Models\NationalLandMedicalLocation;
use App\Services\Address\FacilityMatchingKeys;
use App\Services\Address\MunicipalityResolver;
use App\Services\MedicalInfoNet\MedicalInfoNetMatcher;

/**
 * Replaces a 町丁目-level (or missing) location that the 医療情報ネット did
 * not improve either with the 国土数値情報「医療機関」's position for the same
 * facility. Measured on all of Japan (2026-10-11), those agree with the
 * Address Base Registry's 住居/地番 points within 8-37m (median; over 10km
 * for 4 of 54,000), and for 町丁目-level facilities they are a median 263m
 * from the 町丁目 point.
 *
 * The data is from 2020年度, so a facility that has moved since would be
 * put where it was: a facility is matched as MedicalInfoNetMatcher does
 * (the rows share its columns), and then only when the address there is
 * its own address too. Addresses are compared from after the municipality
 * (as the rows' keys are made), since the data writes some municipalities
 * differently from the bureaus (Yokohama's wards without the city).
 *
 * The position is kept only when plausible: within 10km of the 町丁目 point
 * (the 大字 of a rural town can be that large), or with no location at all,
 * within 30km of where the municipality's other facilities are. Pharmacies
 * are not in the data.
 */
final class NationalLandLocator
{
    private const int TOWN_TOLERANCE_METERS = 10_000;

    private const int MUNICIPALITY_TOLERANCE_METERS = 30_000;

    public function __construct(
        private readonly MedicalInfoNetMatcher $matcher,
        private readonly FacilityMatchingKeys $keys,
        private readonly MunicipalityResolver $municipalities,
        private readonly MunicipalityCenters $municipalityCenters,
    ) {}

    /**
     * @param  array<int, array{institution_type: int, municipality_code: ?string, name: string, address: string}>  $facilities  facility id => attributes, all in $prefecture
     * @param  array<int, GeocodeResult|null>  $results  the locations found so far for those facilities
     * @return array<int, GeocodeResult|null>
     */
    public function refine(Prefecture $prefecture, array $facilities, array $results): array
    {
        $candidates = array_filter(
            $facilities,
            fn (array $facility, int $id): bool => $facility['municipality_code'] !== null
                && $facility['institution_type'] !== InstitutionType::Pharmacy->value
                && ($results[$id] === null || $results[$id]->level === GeocodeLevel::Town),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($candidates === []) {
            return $results;
        }

        $index = $this->matcher->index(
            NationalLandMedicalLocation::query()
                ->where('municipality_code', 'like', $prefecture->value.'%')
                ->select(['id', 'institution_type', 'municipality_code', 'name_key', 'address_key', 'latitude', 'longitude'])
                ->toBase()
                ->cursor(),
        );
        $centers = null;

        foreach ($candidates as $id => $facility) {
            $town = $this->municipalities->match($prefecture->value, $facility['address'])['remainder'] ?? null;
            $match = $town === null ? null : $this->matcher->match([...$facility, 'address' => $town], $index);

            if ($match === null || $match['position'] === null || $match['address'] !== $this->keys->address($town)) {
                continue;
            }

            $reference = $results[$id] !== null
                ? [[$results[$id]->latitude, $results[$id]->longitude], self::TOWN_TOLERANCE_METERS]
                : [($centers ??= $this->municipalityCenters->of($prefecture))[$facility['municipality_code']] ?? null, self::MUNICIPALITY_TOLERANCE_METERS];

            if ($reference[0] !== null && $this->municipalityCenters->distance($match['position'], $reference[0]) <= $reference[1]) {
                $results[$id] = new GeocodeResult(GeocodeLevel::NationalLand, $match['position'][0], $match['position'][1]);
            }
        }

        return $results;
    }
}
