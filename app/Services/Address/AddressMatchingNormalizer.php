<?php

namespace App\Services\Address;

use App\Services\Text\AddressNormalizer;

/**
 * Brings facility addresses and Address Base Registry names into one
 * spelling so they can be compared by prefix: AddressNormalizer's NFKC +
 * itaiji normalization, plus the variations that differ between the two
 * sources -- dash characters between numbers, ケ/ヶ (茅ケ崎/茅ヶ崎), and
 * 丁目 written in kanji ("二丁目") or in digits ("2丁目").
 */
final class AddressMatchingNormalizer
{
    private const array KANJI_DIGITS = ['〇' => 0, '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];

    public function __construct(private readonly AddressNormalizer $addressNormalizer) {}

    public function normalize(string $value): string
    {
        $value = $this->addressNormalizer->normalize($value);
        $value = (string) preg_replace('/(?<=\d)[‐‑‒–—―−](?=\d)/u', '-', $value);
        $value = str_replace(['ケ', 'ヵ'], 'ヶ', $value);

        return (string) preg_replace_callback(
            '/([〇一二三四五六七八九十]+)丁目/u',
            fn (array $matches): string => $this->kanjiToNumber($matches[1]).'丁目',
            $value,
        );
    }

    /**
     * Kanji numerals as used for 丁目 (up to 九十九): "十" alone is 10,
     * "二十" 20, "二十三" 23.
     */
    private function kanjiToNumber(string $kanji): string
    {
        $total = 0;
        $digit = 0;

        foreach (mb_str_split($kanji) as $character) {
            if ($character === '十') {
                $total += ($digit === 0 ? 1 : $digit) * 10;
                $digit = 0;
            } else {
                $digit = self::KANJI_DIGITS[$character];
            }
        }

        return (string) ($total + $digit);
    }
}
