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

    /**
     * The lowercase key used in RHB_CATEGORIES and in download paths.
     */
    public function key(): string
    {
        return strtolower($this->name);
    }

    /**
     * The institution types a list of this category contains.
     *
     * @return list<InstitutionType>
     */
    public function institutionTypes(): array
    {
        return match ($this) {
            self::Medical => [InstitutionType::Hospital, InstitutionType::Clinic],
            self::Dental => [InstitutionType::DentalClinic],
            self::Pharmacy => [InstitutionType::Pharmacy],
        };
    }
}
