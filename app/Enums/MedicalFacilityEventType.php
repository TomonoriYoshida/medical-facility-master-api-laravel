<?php

namespace App\Enums;

/**
 * 施設の変化の種類。公開データに新たに載った施設は「新規」、載らなくなった施設は「廃止」、載り続けて内容が変わった施設は「変更」です。
 */
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
