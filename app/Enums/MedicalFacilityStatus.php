<?php

namespace App\Enums;

enum MedicalFacilityStatus: int
{
    case Active = 1;
    case Closed = 2;
    case Suspended = 3;
}
