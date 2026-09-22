<?php

namespace App\Services\Mhlw\Import;

/**
 * Maps one hospital/clinic/dental facility CSV row into a medical_facilities
 * attribute array. Pure array-in/array-out: does not look up existing
 * records, does not set last_seen_mhlw_dataset_download_id or status --
 * those are only knowable at import-run time (Phase 3/4's responsibility).
 */
final class FacilityRowMapper
{
    /**
     * @var list<string>
     */
    private const array ALL_BED_ATTRIBUTES = [
        'general_beds',
        'sanatorium_beds',
        'sanatorium_beds_medical_insurance',
        'sanatorium_beds_care_insurance',
        'psychiatric_beds',
        'tuberculosis_beds',
        'infectious_disease_beds',
        'total_beds',
    ];

    public function __construct(
        private readonly ClosureScheduleParser $closureScheduleParser = new ClosureScheduleParser,
    ) {}

    /**
     * @param  array<int, string>  $row
     * @return array<string, mixed>
     */
    public function map(array $row, FacilityColumnLayout $layout): array
    {
        return [
            'source_id' => $row[0],
            'institution_type' => $layout->institutionType,
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
            'business_hours' => null,
            'reception_hours' => null,
            ...$this->bedAttributes($row, $layout),
        ];
    }

    /**
     * @param  array<int, string>  $row
     * @return array<string, int|null>
     */
    private function bedAttributes(array $row, FacilityColumnLayout $layout): array
    {
        $attributes = array_fill_keys(self::ALL_BED_ATTRIBUTES, null);

        foreach ($layout->bedAttributeNames as $index => $name) {
            $attributes[$name] = NullableScalar::int($row[$layout->bedColumnsStart() + $index] ?? '');
        }

        return $attributes;
    }
}
