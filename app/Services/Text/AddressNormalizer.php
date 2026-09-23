<?php

namespace App\Services\Text;

final class AddressNormalizer
{
    public function __construct(private readonly ItaijiNormalizer $itaijiNormalizer) {}

    /**
     * Applies ItaijiNormalizer's NFKC + itaiji normalization (converts
     * full-width digits/hyphens to half-width, e.g. "３－１５" -> "3-15"),
     * then fixes one address-specific misuse real data confirmed: the
     * katakana prolonged-sound mark "ー" used as a banchi-number separator
     * (e.g. "157ー1"). That mark is indistinguishable from its legitimate
     * use inside katakana building names (e.g. "ガーデンハウス") except by
     * context, so it is only rewritten to a hyphen when directly flanked
     * by digits on both sides -- never when flanked by kana.
     */
    public function normalize(string $value): string
    {
        $value = $this->itaijiNormalizer->normalize($value);

        return preg_replace('/(?<=\d)ー(?=\d)/u', '-', $value);
    }
}
