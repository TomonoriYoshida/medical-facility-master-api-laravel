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
 */
final class TownMatcher
{
    /** @var array<string, array{id: string, residential: bool}> spelling => 町字, longest spelling first */
    private array $names = [];

    /** @var array<string, array<string, array{id: string, residential: bool}>> 大字・町名 => 丁目 => 町字 */
    private array $chomes = [];

    /** @var array<string, list<array{id: string, residential: bool}>> 小字 => 町字 */
    private array $koazas = [];

    /**
     * @param  iterable<array<string, string>>  $towns  mt_town rows of one municipality
     */
    public function __construct(iterable $towns, AddressMatchingNormalizer $normalizer)
    {
        /** @var array<string, array<string, array{id: string, residential: bool}>> spelling with the 小字 left out => 町字 by id */
        $withoutKoaza = [];

        foreach ($towns as $town) {
            $entry = ['id' => $town['machiaza_id'], 'residential' => $town['rsdt_addr_flg'] === '1'];
            // Some prefectures (e.g. Okinawa) write 大字 as "字豊見城".
            $oaza = (string) preg_replace('/^(大字|字)/u', '', $normalizer->normalize($town['oaza_cho']));
            $koaza = (string) preg_replace('/^字/u', '', $normalizer->normalize($town['koaza']));
            $chome = $town['chome_number'] !== '' ? $town['chome_number'].'丁目' : '';

            $this->names[$oaza.$chome.$koaza] ??= $entry;

            if ($koaza !== '') {
                $this->names[$oaza.$chome.'字'.$koaza] ??= $entry;
                $withoutKoaza[$oaza.$chome][$entry['id']] = $entry;
                $this->koazas[$koaza][] = $entry;
            }

            if ($town['chome_number'] !== '') {
                $this->chomes[$oaza][$town['chome_number']] ??= $entry;
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
     * @return array{id: string, residential: bool, rest: string}|null
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
     * @return array{id: string, residential: bool, rest: string}|null
     */
    private function matchTown(string $remainder): ?array
    {
        $remainder = (string) preg_replace('/^\s*(大字|字)?/u', '', $remainder);

        // 丁目 omitted before a hyphen is checked first: a town with 丁目 is
        // often also listed bare (広小路 next to 広小路一丁目, ...), and
        // "広小路5-10" means 五丁目10, not 広小路 5番地.
        foreach ($this->chomes as $oaza => $chomes) {
            if (str_starts_with($remainder, $oaza) && preg_match('/^\s*(\d+)-/u', substr($remainder, strlen($oaza)), $matches) === 1 && isset($chomes[$matches[1]])) {
                return [...$chomes[$matches[1]], 'rest' => substr($remainder, strlen($oaza) + strlen($matches[0]))];
            }
        }

        foreach ($this->names as $name => $town) {
            if (str_starts_with($remainder, $name)) {
                return [...$town, 'rest' => substr($remainder, strlen($name))];
            }
        }

        foreach ($this->koazas as $koaza => $towns) {
            if (count($towns) === 1 && str_starts_with($remainder, $koaza)) {
                return [...$towns[0], 'rest' => substr($remainder, strlen($koaza))];
            }
        }

        return null;
    }
}
