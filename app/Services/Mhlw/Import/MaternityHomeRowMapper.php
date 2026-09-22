<?php

namespace App\Services\Mhlw\Import;

use App\Enums\InstitutionType;

/**
 * Maps one 助産所 (maternity home) CSV row into a medical_facilities
 * attribute array. Same 13-column core and 44-column closure block as
 * hospital/clinic/dental, but has no department concept and no bed counts;
 * instead it carries two genuinely distinct hour categories -- 就業時間帯
 * (working hours, 3 bands) and 外来受付時間帯 (reception hours, 3 bands) --
 * mapped to business_hours/reception_hours respectively. Pure array-in/
 * array-out; see FacilityRowMapper's docblock for the same DB-free scope
 * boundary.
 */
final class MaternityHomeRowMapper
{
    private const int WORKING_HOURS_START = 57;

    private const int RECEPTION_HOURS_START = 105;

    private const int BAND_COUNT = 3;

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
            'institution_type' => InstitutionType::MaternityHome,
            'name' => $row[1],
            'name_kana' => NullableScalar::string($row[2] ?? ''),
            'short_name' => NullableScalar::string($row[3] ?? ''),
            'short_name_kana' => NullableScalar::string($row[4] ?? ''),
            'name_en' => NullableScalar::string($row[5] ?? ''),
            'prefecture_code' => $row[7],
            'city_code' => $row[8],
            'address' => $row[9],
            'latitude' => NullableScalar::float($row[10] ?? ''),
            'longitude' => NullableScalar::float($row[11] ?? ''),
            'website_url' => NullableScalar::string($row[12] ?? ''),
            'closure_schedule' => $this->closureScheduleParser->parse($row, ClosureScheduleConfig::standard()),
            'business_hours' => $this->timeSlotParser->parseBands(
                $row,
                self::WORKING_HOURS_START,
                self::BAND_COUNT,
                includeHoliday: true,
            ),
            'reception_hours' => $this->timeSlotParser->parseBands(
                $row,
                self::RECEPTION_HOURS_START,
                self::BAND_COUNT,
                includeHoliday: true,
            ),
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
