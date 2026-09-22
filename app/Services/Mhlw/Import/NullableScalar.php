<?php

namespace App\Services\Mhlw\Import;

/**
 * MHLW CSV cells use an empty string for "not provided", shared by every
 * row mapper's optional columns (name_kana, latitude, bed counts, ...).
 */
final class NullableScalar
{
    public static function string(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function float(string $value): ?float
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : (float) $trimmed;
    }

    public static function int(string $value): ?int
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : (int) $trimmed;
    }
}
