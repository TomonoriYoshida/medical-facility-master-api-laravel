<?php

namespace App\Enums;

enum MedicalFacilityEventType: int
{
    case Created = 1;
    case Removed = 2;
    case Updated = 3;
}
