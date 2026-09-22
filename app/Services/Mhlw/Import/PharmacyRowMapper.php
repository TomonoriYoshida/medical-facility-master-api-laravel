<?php

namespace App\Services\Mhlw\Import;

use App\Enums\InstitutionType;

/**
 * Maps one 薬局 (pharmacy) CSV row into a medical_facilities attribute
 * array. Genuinely different core-column shape from the other 4 types (11
 * columns, no 略称/略称（フリガナ） fields at all) and a different 53-column
 * closure-schedule shape (see ClosureScheduleConfig::pharmacy()). Has no
 * department concept, no bed counts, and only one hour category (開店時間帯,
 * 4 bands -> business_hours; reception_hours is left null, pharmacies have
 * no separate reception concept in the source data). Pure array-in/
 * array-out; see FacilityRowMapper's docblock for the same DB-free scope
 * boundary.
 */
final class PharmacyRowMapper
{
    private const int OPENING_HOURS_START = 64;

    private const int BAND_COUNT = 4;

    public function __construct(
        private readonly ClosureScheduleParser $closureScheduleParser = new ClosureScheduleParser,
        private readonly TimeSlotParser $timeSlotParser = new TimeSlotParser,
    ) {}

    /**
     * @param  array<int, string>  $row
     * @return array<string, mixed>
     */
    public function map(array $row): array
    {
        return [
            'source_id' => $row[0],
            'institution_type' => InstitutionType::Pharmacy,
            'name' => $row[1],
            'name_kana' => NullableScalar::string($row[2] ?? ''),
            'short_name' => null,
            'short_name_kana' => null,
            'name_en' => NullableScalar::string($row[3] ?? ''),
            'prefecture_code' => $row[5],
            'city_code' => $row[6],
            'address' => $row[7],
            'latitude' => NullableScalar::float($row[8] ?? ''),
            'longitude' => NullableScalar::float($row[9] ?? ''),
            'website_url' => NullableScalar::string($row[10] ?? ''),
            'closure_schedule' => $this->closureScheduleParser->parse($row, ClosureScheduleConfig::pharmacy()),
            'business_hours' => $this->timeSlotParser->parseBands(
                $row,
                self::OPENING_HOURS_START,
                self::BAND_COUNT,
                includeHoliday: true,
            ),
            'reception_hours' => null,
            'general_beds' => null,
            'sanatorium_beds' => null,
            'sanatorium_beds_medical_insurance' => null,
            'sanatorium_beds_care_insurance' => null,
            'psychiatric_beds' => null,
            'tuberculosis_beds' => null,
            'infectious_disease_beds' => null,
            'total_beds' => null,
        ];
    }
}
