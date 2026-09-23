<?php

namespace App\Services\Rhb\Import;

use App\Enums\MedicalFacilityStatus;

/**
 * Column J carries, across a record's rows: an institution-type label
 * (病院/診療所/薬局), sometimes a bed-type-category label, sometimes a
 * hospital sub-designation (e.g. "特定機能" for a university/advanced-
 * function hospital -- verified in real data to occupy the row position
 * that plain 病院/診療所/薬局 records use for the type label, pushing the
 * real "病院" value to a later row instead), and 現存 or 休止 (the actual
 * current status flag) on whichever row is last relevant. Real data: 現存
 * 99.28%, 休止 0.72%. Because the type label's row position isn't fixed,
 * every row is scanned for whichever one actually holds 病院/診療所/薬局,
 * rather than assuming row 0.
 *
 * Note: "診療所" alone is ambiguous between Clinic and DentalClinic (both
 * a medical clinic and a dental clinic use this same label) -- resolving
 * that ambiguity requires the RhbCategory the record was read from, which
 * this class does not have. It returns the raw label only; the caller
 * (InsuredFacilityRecordMapper) resolves the final InstitutionType.
 */
final class InstitutionStatusParser
{
    private const array TYPE_LABELS = ['病院', '診療所', '薬局'];

    /**
     * @param  list<array<int, string>>  $rows
     * @return array{typeLabel: string, status: MedicalFacilityStatus}
     */
    public function parse(array $rows): array
    {
        $typeLabel = '';
        $status = MedicalFacilityStatus::Active;

        foreach ($rows as $row) {
            $value = trim($row[9] ?? '');

            if (in_array($value, self::TYPE_LABELS, true)) {
                $typeLabel = $value;
            }

            if ($value === '休止') {
                $status = MedicalFacilityStatus::Suspended;
            }
        }

        return ['typeLabel' => $typeLabel, 'status' => $status];
    }
}
