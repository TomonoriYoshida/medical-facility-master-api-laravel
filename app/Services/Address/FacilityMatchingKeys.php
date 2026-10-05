<?php

namespace App\Services\Address;

use App\Enums\Prefecture;

/**
 * Keys for finding the same facility in another data source that shares no
 * code with the bureaus' data (the MHLW 医療情報ネット): its name without the
 * corporation in front, and its address down to the 番地 numbers. Both sides
 * go through the same functions, so spelling differences cancel out.
 */
final class FacilityMatchingKeys
{
    /**
     * The corporation some sources put before the facility's own name
     * ("医療法人社団明生会イムス札幌…", "医療法人愛全病院", "社団医療法人養生会かしま病院").
     */
    private const string CORPORATION = '/^(?:社団|財団)?(?:社会医療法人|医療法人|特定医療法人|一般社団法人|一般財団法人|公益社団法人|公益財団法人|社会福祉法人|学校法人|独立行政法人|地方独立行政法人|国立大学法人|株式会社|有限会社|合同会社)(?:社団|財団)?(?:\S{1,20}?(?:会|団|社|機構|法人))?/u';

    /**
     * The same, abbreviated as the 医療情報ネット often writes it
     * ("(医)成心会なりた内科クリニック", "社団(医)養生会かしま病院").
     */
    private const string ABBREVIATED_CORPORATION = '/^(?:社団|財団)?\((?:医|福|社|財|学|独|公|一|特医|社医)\)(?:社団|財団)?(?:\S{1,20}?(?:会|団|社|機構|法人))?/u';

    /**
     * Punctuation written in one source and left out in the other
     * ("みやざき内科・小児科クリニック" / "みやざき内科小児科クリニック").
     */
    private const array NAME_PUNCTUATION = ['・', '･', '、', '，', ',', '．', '.', '(', ')', '「', '」', '"', "'", '’'];

    /**
     * Spellings of the same word ("皮フ科" / "皮膚科", "付属" / "附属").
     */
    private const array NAME_SPELLINGS = ['付属' => '附属', '皮フ' => '皮膚', 'ひふ科' => '皮膚科'];

    private const array KANJI_DIGITS = ['一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];

    public function __construct(private readonly AddressMatchingNormalizer $normalizer) {}

    public function name(string $name): string
    {
        $name = (string) preg_replace('/\s+/u', '', $this->normalizer->normalize($name));
        $name = (string) preg_replace(self::CORPORATION, '', $name) ?: $name;
        $name = (string) preg_replace(self::ABBREVIATED_CORPORATION, '', $name) ?: $name;
        $name = strtr(str_replace(self::NAME_PUNCTUATION, '', $name), self::NAME_SPELLINGS);

        // Hyphens and long vowel marks are mixed up ("IMSMe-Life" / "IMSMeーLife").
        return (string) preg_replace('/[‐－―-]/u', 'ー', $name);
    }

    /**
     * The address up to its 番地 numbers, written "町名1-2-3": what follows
     * (building, floor, room) is spelled too freely to compare.
     *
     * Numbers that are part of the town name ("北1条西", "5線", "第2地割")
     * stay in it, and a space ends the 番地, so "1-9-1 2階" is "…1-9-1", not
     * "…1-9-12". A space between the town and its numbers ("幸町 5-10-1") is
     * dropped.
     */
    public function address(string $address): string
    {
        $address = trim((string) preg_replace('/\s+/u', ' ', $this->normalizer->normalize($address)));

        foreach (Prefecture::cases() as $prefecture) {
            if (str_starts_with($address, $prefecture->label())) {
                $address = ltrim(substr($address, strlen($prefecture->label())));

                break;
            }
        }

        // 大字 and 字 are written in one source and left out in the other
        // ("弘前市大字安原" / "弘前市安原", "石川字石川" / "石川石川").
        $address = (string) preg_replace('/大字|(?<=\D)字(?=\D)/u', '', $address);
        // Kanji numbers in a town name: "ひらふ五条" / "ひらふ5条".
        // Only 1-99 written with 十 ("二十三条") or a single digit ("五条");
        // other spellings ("一五条") are left as they are.
        $address = (string) preg_replace_callback(
            '/(?<![一二三四五六七八九十])([一二三四五六七八九]?十[一二三四五六七八九]?|[一二三四五六七八九])(?=条|線)/u',
            fn (array $matches): string => (string) $this->kanjiNumber($matches[1]),
            $address,
        );

        $address = (string) preg_replace('/(\d+)丁目/u', '$1-', $address);
        $address = (string) preg_replace('/(\d+)番地?の?/u', '$1-', $address);
        $address = (string) preg_replace('/(\d+)号/u', '$1', $address);
        $address = (string) preg_replace('/(\d+)の(?=\d)/u', '$1-', $address);
        // After the conversions above, so that the space in "1号 2階" (now
        // "1 2階") or "9番 2階" ("9- 2階") still ends the 番地.
        $address = (string) preg_replace('/(?<=[^\d\s-]) (?=\d)/u', '', $address);

        // The 番地 is the first run of numbers not followed by a town-name
        // suffix: "北1条西2-3" is the town "北1条西" and the 番地 "2-3".
        return preg_match('/^((?:\d+(?=[条線地区割])|\D)*?)(\d+(?:-\d+)*)(?![\d条線地区割])/u', $address, $matches) === 1
            ? $matches[1].$matches[2]
            : $address;
    }

    /**
     * 1 to 99 written in kanji ("五", "十二", "二十", "二十三").
     */
    private function kanjiNumber(string $kanji): int
    {
        if (! str_contains($kanji, '十')) {
            return self::KANJI_DIGITS[$kanji];
        }

        [$tens, $ones] = explode('十', $kanji, 2);

        return ($tens === '' ? 1 : self::KANJI_DIGITS[$tens]) * 10 + ($ones === '' ? 0 : self::KANJI_DIGITS[$ones]);
    }
}
