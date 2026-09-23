<?php

namespace App\Enums;

enum InstitutionType: int
{
    case Hospital = 1;
    case Clinic = 2;
    case DentalClinic = 3;
    case Pharmacy = 4;
}
