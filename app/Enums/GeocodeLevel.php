<?php

namespace App\Enums;

/**
 * How precisely a facility's coordinates were located from its address,
 * most precise first. The source is the Digital Agency's Address Base
 * Registry: 住居表示 areas are located by 住居 (〇番〇号) or 街区 (〇番),
 * other areas by 地番 (〇番地〇), and whatever cannot be located that
 * finely falls back to the representative point of its 町丁目 (or of the
 * 大字 a 小字 belongs to).
 */
enum GeocodeLevel: int
{
    case Residence = 1;
    case Block = 2;
    case Parcel = 3;
    case ParcelBase = 4;
    case Town = 5;

    public function label(): string
    {
        return match ($this) {
            self::Residence => '住居',
            self::Block => '街区',
            self::Parcel => '地番',
            self::ParcelBase => '地番（枝番なし）',
            self::Town => '町丁目',
        };
    }
}
