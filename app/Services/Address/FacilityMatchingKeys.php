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
     * ("医療法人社団明生会イムス札幌…", "医療法人愛全病院").
     */
    private const string CORPORATION = '/^(?:社会医療法人|医療法人|特定医療法人|一般社団法人|一般財団法人|公益社団法人|公益財団法人|社会福祉法人|学校法人|独立行政法人|地方独立行政法人|国立大学法人|株式会社|有限会社|合同会社)(?:社団|財団)?(?:\S{1,20}?(?:会|団|社|機構|法人))?/u';

    public function __construct(private readonly AddressMatchingNormalizer $normalizer) {}

    public function name(string $name): string
    {
        $name = (string) preg_replace('/\s+/u', '', $this->normalizer->normalize($name));

        return (string) preg_replace(self::CORPORATION, '', $name) ?: $name;
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
}
