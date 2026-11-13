<?php

namespace App\Services\Text;

final class StationNameNormalizer
{
    public function __construct(private readonly ItaijiNormalizer $itaijiNormalizer) {}

    /**
     * Applies ItaijiNormalizer's NFKC + itaiji normalization, drops spaces
     * and writes the small "ヶ" / "ヵ" as "ケ" / "カ", which station names
     * use interchangeably (市ヶ谷 / 市ケ谷), so a name typed either way
     * matches the data's spelling.
     */
    public function normalize(string $value): string
    {
        $value = $this->itaijiNormalizer->normalize($value);

        return strtr(preg_replace('/\s+/u', '', $value) ?? $value, ['ヶ' => 'ケ', 'ヵ' => 'カ']);
    }
}
