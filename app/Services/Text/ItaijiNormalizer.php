<?php

namespace App\Services\Text;

use App\Models\KanjiVariant;
use Normalizer;

final class ItaijiNormalizer
{
    /**
     * Normalize full-width/half-width forms (via Unicode NFKC) and known
     * Japanese kanji itaiji (e.g. 髙 -> 高) into one canonical spelling, so
     * search input and stored values can be compared consistently.
     */
    public function normalize(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;

        return strtr($value, $this->variantMap());
    }

    /**
     * @return array<string, string>
     */
    private function variantMap(): array
    {
        return once(fn (): array => KanjiVariant::query()
            ->pluck('canonical_character', 'variant_character')
            ->all());
    }
}
