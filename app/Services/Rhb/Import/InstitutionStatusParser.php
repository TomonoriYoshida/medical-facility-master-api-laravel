<?php

namespace App\Services\Rhb\Import;

use App\Enums\MedicalFacilityStatus;

/**
 * Column J carries, across a record's rows: an institution-type label,
 * sometimes a bed-type-category label, and 現存 or 休止 (the actual
 * current status flag) on whichever row is last relevant. Real data: 現存
 * 99.28%, 休止 0.72%.
 *
 * The institution-type label is not always the plain 病院/診療所/薬局
 * string, and its row position is not fixed -- two distinct real-data
 * shapes confirmed:
 * - A hospital sub-designation (e.g. "特定機能" for a university/
 *   advanced-function hospital) can occupy the row position a plain
 *   病院/診療所/薬局 record would use, with the real "病院" value pushed to
 *   a later row instead (Hokkaido).
 * - A sub-designation can instead already contain the base word (e.g.
 *   "総合病院" = general hospital, with no separate plain-病院 row
 *   anywhere in the record) (Tohoku).
 * Both are handled the same way: every row's column J is checked for
 * whether it *contains* 病院/診療所/薬局 as a substring (not an exact
 * match), normalizing to the base label either way.
 *
 * Note: "診療所" alone is ambiguous between Clinic and DentalClinic (both
 * a medical clinic and a dental clinic use this same label) -- resolving
 * that ambiguity requires the RhbCategory the record was read from, which
 * this class does not have. It returns the normalized base label only;
 * the caller (InsuredFacilityRecordMapper) resolves the final
 * InstitutionType.
 */
final class InstitutionStatusParser
{
    /**
     * @var list<string>
     */
    private const array TYPE_MARKERS = ['病院', '診療所', '薬局'];

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

            foreach (self::TYPE_MARKERS as $marker) {
                if (str_contains($value, $marker)) {
                    $typeLabel = $marker;

                    break;
                }
            }

            if ($value === '休止') {
                $status = MedicalFacilityStatus::Suspended;
            }
        }

        return ['typeLabel' => $typeLabel, 'status' => $status];
    }
}
