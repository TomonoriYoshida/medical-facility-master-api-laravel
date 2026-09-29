<?php

namespace App\Enums;

enum MedicalFacilityEventType: int
{
    case Created = 1;
    case Removed = 2;
    case Updated = 3;

    public function label(): string
    {
        return match ($this) {
            self::Created => '新規',
            self::Removed => '廃止',
            self::Updated => '変更',
        };
    }
}
