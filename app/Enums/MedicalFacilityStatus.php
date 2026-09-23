<?php

namespace App\Enums;

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
