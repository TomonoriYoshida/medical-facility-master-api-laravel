<?php

namespace App\Enums;

/**
 * 保険医療機関・保険薬局としての指定状態。公開データに載らなくなった施設は「廃止」になります。
 */
enum MedicalFacilityStatus: int
{
    case Active = 1;
    case Closed = 2;
    case Suspended = 3;

    public function label(): string
    {
        return match ($this) {
            self::Active => '指定中',
            self::Closed => '廃止',
            self::Suspended => '休止',
        };
    }
}
