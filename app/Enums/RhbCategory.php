<?php

namespace App\Enums;

/**
 * The 3 institution categories each 地方厚生局 publishes a separate list
 * for (医科/歯科/薬局). Column I (bed counts / department categories) only
 * ever appears for Medical/Dental; Pharmacy lists never carry it.
 */
enum RhbCategory: int
{
    case Medical = 1;
    case Dental = 2;
    case Pharmacy = 3;
}
