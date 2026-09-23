<?php

namespace App\Enums;

enum InstitutionType: int
{
    case Hospital = 1;
    case Clinic = 2;
    case DentalClinic = 3;
    case Pharmacy = 4;

    public function label(): string
    {
        return match ($this) {
            self::Hospital => '病院',
            self::Clinic => '診療所',
            self::DentalClinic => '歯科診療所',
            self::Pharmacy => '薬局',
        };
    }
}
