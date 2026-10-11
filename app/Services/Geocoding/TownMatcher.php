<?php

namespace App\Services\Geocoding;

use App\Services\Address\AddressMatchingNormalizer;

/**
 * Finds the 町字 (Address Base Registry machiaza) an address continues
 * with, within one municipality. The registry and the bureaus' data spell
 * the same place differently, so each 町字 is indexed under the spellings
 * real addresses use:
 *
 * - with and without its leading 大字 / 字 (the registry writes "大字北堀" or
 *   "字豊見城", addresses mostly "北堀", "豊見城")
 * - with and without its 小字 ("大字鶴賀田町" is usually written "鶴賀田町")
 * - its 小字 alone, when no other 町字 of the municipality shares it
 *   ("長野市桜枝町" for 大字長野字桜枝町)
 * - 丁目 omitted before a hyphenated number ("九段南1-6-5" for
 *   九段南一丁目6番5号)
 * - 丁目 written "丁" where the registry does so too (Sakai's "桃山台２丁"
 *   is written "桃山台二丁3番4号")
 *
 * A 町字 where 住居表示 covers only part of the area is listed twice, once
 * with rsdt_addr_flg 1 and once with 0; its numbers are then looked up both
 * as 街区・住居 and as 地番.
 *
 * @phpstan-type Town array{id: string, residential: bool, parcel: bool}
 * @phpstan-type TownMatch array{id: string, residential: bool, parcel: bool, rest: string}
 */
final class TownMatcher
{
    /** @var array<string, Town> spelling => 町字, longest spelling first */
    private array $names = [];

    /** @var array<string, array<string, Town>> 大字・町名 => 丁目 => 町字 */
    private array $chomes = [];

    /** @var array<string, true> 大字・町名 whose 丁目 the registry writes "N丁" */
    private array $choSpellings = [];

    /** @var array<string, array<string, Town>> 小字 => 町字 by id */
    private array $koazas = [];

    /**
     * @param  list<array<string, string>>  $towns  mt_town rows of one municipality
     */
    public function __construct(array $towns, private readonly AddressMatchingNormalizer $normalizer)
    {
        /** @var array<string, array{residential?: true, parcel?: true}> machiaza_id => which rsdt_addr_flg it is listed with */
        $flags = [];

        foreach ($towns as $town) {
            $flags[$town['machiaza_id']][$town['rsdt_addr_flg'] === '1' ? 'residential' : 'parcel'] = true;
        }

        /** @var array<string, array<string, Town>> spelling with the 小字 left out => 町字 by id */
        $withoutKoaza = [];

        foreach ($towns as $town) {
            $entry = [
                'id' => $town['machiaza_id'],
                'residential' => isset($flags[$town['machiaza_id']]['residential']),
                'parcel' => isset($flags[$town['machiaza_id']]['parcel']),
            ];
            // Some prefectures (e.g. Okinawa) write 大字 as "字豊見城".
            $oaza = (string) preg_replace('/^(大字|字)/u', '', $normalizer->normalize($town['oaza_cho']));
            $koaza = (string) preg_replace('/^字/u', '', $normalizer->normalize($town['koaza']));
            $chome = $town['chome_number'] !== '' ? $town['chome_number'].'丁目' : '';

            $this->names[$oaza.$chome.$koaza] ??= $entry;

            if ($koaza !== '') {
                $this->names[$oaza.$chome.'字'.$koaza] ??= $entry;
                $withoutKoaza[$oaza.$chome][$entry['id']] = $entry;
                $this->koazas[$koaza][$entry['id']] = $entry;
            }

            if ($town['chome_number'] !== '') {
                $this->chomes[$oaza][$town['chome_number']] ??= $entry;

                if (preg_match('/丁$/u', $normalizer->normalize($town['chome'])) === 1) {
                    $this->choSpellings[$oaza] = true;
                }
            }
        }

        // With the 小字 left out, a spelling is used only when it can mean one
        // 町字: "福室" is shared by many 小字 (福室字下河原, 福室字田中, ...) and
        // must not capture "福室5-10-5", which is 福室五丁目.
        foreach ($withoutKoaza as $spelling => $entries) {
            if (count($entries) === 1) {
                $this->names[$spelling] ??= reset($entries);
            }
        }

        uksort($this->names, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * @param  string  $remainder  the address after the municipality name, in AddressMatchingNormalizer's spelling
     * @return TownMatch|null
     */
    public function match(string $remainder): ?array
    {
        // Kyoto's street-based form puts the street and direction before the
        // town ("高倉通姉小路下ル東片町621"); it is tried only when the address
        // does not match as written.
        return $this->matchTown($remainder)
            ?? (preg_match('/^\s*\S{1,25}?(?:上る|上ル|下る|下ル|東入る|東入ル|東入|西入る|西入ル|西入)/u', $remainder, $matches) === 1
                ? $this->matchTown(substr($remainder, strlen($matches[0])))
                : null);
    }

    /**
     * @return TownMatch|null
     */
    private function matchTown(string $remainder): ?array
    {
        $remainder = (string) preg_replace('/^\s*(大字|字)?/u', '', $remainder);

        // 丁目 omitted before a hyphen is checked first: a town with 丁目 is
        // often also listed bare (広小路 next to 広小路一丁目, ...), and
        // "広小路5-10" means 五丁目10, not 広小路 5番地.
        foreach ($this->chomes as $oaza => $chomes) {
            if (! str_starts_with($remainder, $oaza)) {
                continue;
            }

            $afterOaza = substr($remainder, strlen($oaza));

            if (preg_match('/^\s*(\d+)-/u', $afterOaza, $matches) === 1 && isset($chomes[$matches[1]])) {
                return [...$chomes[$matches[1]], 'rest' => substr($afterOaza, strlen($matches[0]))];
            }

            // "二丁3番4号": AddressMatchingNormalizer turns only "二丁目" into
            // digits. A number must follow, so that a town named like "西一丁田"
            // is not read as 西一丁 + 田. A 丁 the town does not have
            // ("九丁3番4号") matches nothing rather than falling back to the
            // bare town, where "九丁" would be skipped as an unlisted 小字.
            if (isset($this->choSpellings[$oaza]) && preg_match('/^\s*(\d+|[〇一二三四五六七八九十]+)丁(?=\s*\d)/u', $afterOaza, $matches) === 1) {
                $number = ctype_digit($matches[1]) ? (ltrim($matches[1], '0') ?: '0') : $this->normalizer->kanjiToNumber($matches[1]);

                return isset($chomes[$number]) ? [...$chomes[$number], 'rest' => substr($afterOaza, strlen($matches[0]))] : null;
            }
        }

        foreach ($this->names as $name => $town) {
            if (str_starts_with($remainder, $name)) {
                return [...$town, 'rest' => substr($remainder, strlen($name))];
            }
        }

        foreach ($this->koazas as $koaza => $towns) {
            if (count($towns) === 1 && str_starts_with($remainder, $koaza)) {
                return [...reset($towns), 'rest' => substr($remainder, strlen($koaza))];
            }
        }

        return null;
    }
}
