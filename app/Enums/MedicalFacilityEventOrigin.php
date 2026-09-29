<?php

namespace App\Enums;

/**
 * Why an import recorded a medical_facility_events row, so that only real
 * changes in the source data are presented as such:
 *
 * - Baseline: the first import of a prefecture + category (at launch, or
 *   when the prefecture is added to RHB_PREFECTURES later). Every facility
 *   in it is "Created" merely because we started tracking it.
 * - Detected: a difference between two publications -- an actual opening,
 *   closure or change.
 * - Reprocessed: a difference found while importing a publication the data
 *   already came from (rhb:import --force, or a re-run of a publication
 *   never marked imported). The source data is the same, so the difference
 *   comes from our own parser/normalizer changes. FacilityUpserter decides
 *   it per facility by comparing publication dates.
 */
enum MedicalFacilityEventOrigin: int
{
    case Baseline = 1;
    case Detected = 2;
    case Reprocessed = 3;

    public function label(): string
    {
        return match ($this) {
            self::Baseline => '初回取込',
            self::Detected => '検知',
            self::Reprocessed => '再処理',
        };
    }
}
