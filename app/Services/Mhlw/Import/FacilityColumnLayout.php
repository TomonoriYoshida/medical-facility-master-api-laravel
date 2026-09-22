<?php

namespace App\Services\Mhlw\Import;

use App\Enums\InstitutionType;

/**
 * Hospital/clinic/dental facility CSVs share an identical first 57 columns
 * (13 core + 44 closure-schedule); only the bed-count tail differs, and not
 * just in column count -- clinic's 5 bed attributes are a genuine subset in
 * a different final position (total_beds immediately follows the first 4,
 * skipping psychiatric/tuberculosis/infectious_disease entirely), not just
 * "the first N of hospital's 8", so each type's attribute list is spelled
 * out explicitly rather than derived from a shared list + count.
 */
final readonly class FacilityColumnLayout
{
    private const int BED_COLUMNS_START = 57;

    /**
     * @param  list<string>  $bedAttributeNames  in real column order, starting at BED_COLUMNS_START
     */
    public function __construct(
        public InstitutionType $institutionType,
        public array $bedAttributeNames,
    ) {}

    public static function hospital(): self
    {
        return new self(InstitutionType::Hospital, [
            'general_beds',
            'sanatorium_beds',
            'sanatorium_beds_medical_insurance',
            'sanatorium_beds_care_insurance',
            'psychiatric_beds',
            'tuberculosis_beds',
            'infectious_disease_beds',
            'total_beds',
        ]);
    }

    public static function clinic(): self
    {
        return new self(InstitutionType::Clinic, [
            'general_beds',
            'sanatorium_beds',
            'sanatorium_beds_medical_insurance',
            'sanatorium_beds_care_insurance',
            'total_beds',
        ]);
    }

    public static function dental(): self
    {
        return new self(InstitutionType::DentalClinic, []);
    }

    public function bedColumnsStart(): int
    {
        return self::BED_COLUMNS_START;
    }
}
