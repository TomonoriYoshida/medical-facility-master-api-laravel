<?php

namespace App\Services\Rhb\Import;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use InvalidArgumentException;

/**
 * Composes the column-level parsers into one medical_facilities attribute
 * array per record. Pure array-in/array-out: does not look up existing
 * records, does not set last_seen_rhb_dataset_download_id or resolve
 * latitude/longitude (never present in this data source) -- those belong
 * to the Sync layer / import-run orchestration.
 */
final class InsuredFacilityRecordMapper
{
    public function __construct(
        private readonly FacilityCodeParser $facilityCodeParser = new FacilityCodeParser,
        private readonly AddressParser $addressParser = new AddressParser,
        private readonly PhoneNumberParser $phoneNumberParser = new PhoneNumberParser,
        private readonly DesignationHistoryParser $designationHistoryParser = new DesignationHistoryParser,
        private readonly BedAndDepartmentParser $bedAndDepartmentParser = new BedAndDepartmentParser,
        private readonly DepartmentCategoryClassifier $departmentCategoryClassifier = new DepartmentCategoryClassifier,
        private readonly InstitutionStatusParser $institutionStatusParser = new InstitutionStatusParser,
        private readonly PrivateUseCharacters $privateUseCharacters = new PrivateUseCharacters,
    ) {}

    /**
     * @param  array{serial: int, rows: list<array<int, string>>}  $record
     * @return array<string, mixed>
     */
    public function map(array $record, RhbCategory $category, RhbBureau $bureau, string $prefectureCode): array
    {
        // 外字 first, in every cell, so that each parser sees real characters.
        $rows = array_map(
            fn (array $row): array => array_map($this->privateUseCharacters->replace(...), $row),
            $record['rows'],
        );

        $address = $this->addressParser->parse($rows[0][3] ?? '');
        $designation = $this->designationHistoryParser->parse($rows);
        $institutionStatus = $this->institutionStatusParser->parse($rows);

        // Column I (bed counts / department tokens) never appears for
        // pharmacy records at all -- confirmed absent across every real
        // pharmacy file sampled, not merely empty.
        $bedAndDepartments = $category === RhbCategory::Pharmacy
            ? ['bedCounts' => [], 'departmentTokens' => []]
            : $this->bedAndDepartmentParser->parse($rows);

        return [
            'facility_code' => $this->facilityCodeParser->parse($rows),
            'bureau_code' => $bureau,
            'institution_type' => $this->resolveInstitutionType($category, $institutionStatus['typeLabel']),
            'status' => $institutionStatus['status'],
            'name' => trim($rows[0][2] ?? ''),
            'prefecture_code' => $prefectureCode,
            'postal_code' => $address['postalCode'],
            'address' => $address['address'],
            'phone_number' => $this->phoneNumberParser->parse($rows[0][4] ?? ''),
            'founder_name' => $this->nullableString($rows[0][5] ?? ''),
            'administrator_name' => $this->nullableString($rows[0][6] ?? ''),
            'designated_on' => $designation['designatedOn'],
            'designation_history' => $designation['history'],
            'bed_counts' => $bedAndDepartments['bedCounts'] === [] ? null : $bedAndDepartments['bedCounts'],
            'department_categories' => $this->departmentCategories($category, $bedAndDepartments['departmentTokens']),
        ];
    }

    /**
     * A dental designation (歯科) may only list dental departments (歯科,
     * 小児歯科, 矯正歯科, 歯科口腔外科), so every token in a dental list is
     * Dentistry. Classifying them one by one sent the common abbreviation
     * "小歯" (小児歯科) to Pediatrics through the "小" fallback.
     *
     * @param  list<string>  $tokens
     * @return list<DepartmentBaseCategory>
     */
    private function departmentCategories(RhbCategory $category, array $tokens): array
    {
        if ($category === RhbCategory::Dental) {
            return $tokens === [] ? [] : [DepartmentBaseCategory::Dentistry];
        }

        return $this->departmentCategoryClassifier->classify($tokens);
    }

    /**
     * "診療所" alone is ambiguous between Clinic and DentalClinic -- the
     * category the file was read under (not the label itself) resolves it.
     */
    private function resolveInstitutionType(RhbCategory $category, string $typeLabel): InstitutionType
    {
        return match ($category) {
            RhbCategory::Pharmacy => InstitutionType::Pharmacy,
            RhbCategory::Dental => InstitutionType::DentalClinic,
            RhbCategory::Medical => match ($typeLabel) {
                '病院' => InstitutionType::Hospital,
                '診療所' => InstitutionType::Clinic,
                default => throw new InvalidArgumentException("Unexpected institution type label: \"{$typeLabel}\""),
            },
        };
    }

    private function nullableString(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
