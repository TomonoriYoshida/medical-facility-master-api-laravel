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
 * - Reprocessed: re-importing an already-imported publication (rhb:import
 *   --force). The data is the same, so any difference comes from our own
 *   parser/normalizer changes, not from the source.
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
