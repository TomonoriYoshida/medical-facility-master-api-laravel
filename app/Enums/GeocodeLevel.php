<?php

namespace App\Enums;

/**
 * How precisely a facility's coordinates were located from its address,
 * most precise first. The source is the Digital Agency's Address Base
 * Registry: 住居表示 areas are located by 住居 (〇番〇号) or 街区 (〇番),
 * other areas by 地番 (〇番地〇), and whatever cannot be located that
 * finely falls back to the representative point of its 町丁目 (or of the
 * 大字 a 小字 belongs to).
 *
 * MedicalInfoNet is a source rather than a precision: the coordinates the
 * MHLW 医療情報ネット publishes for the facility, used instead of a 町丁目
 * point (or of nothing). They agree with this registry's 住居/地番 points
 * within tens of meters for most facilities.
 *
 * NationalLand is a source too: the position the MLIT 国土数値情報「医療機関」
 * (2020年度) gives the facility, used where neither of the above reaches
 * further than the 町丁目, and only when its address there is the
 * facility's own (see NationalLandLocator).
 */
enum GeocodeLevel: int
{
    case Residence = 1;
    case Block = 2;
    case Parcel = 3;
    case ParcelBase = 4;
    case Town = 5;
    case MedicalInfoNet = 6;
    case NationalLand = 7;

    public function label(): string
    {
        return match ($this) {
            self::Residence => '住居',
            self::Block => '街区',
            self::Parcel => '地番',
            self::ParcelBase => '地番（枝番なし）',
            self::Town => '町丁目',
            self::MedicalInfoNet => '医療情報ネット',
            self::NationalLand => '国土数値情報',
        };
    }
}
