<?php

namespace App\Services\Geocoding;

use App\Enums\AbrDataset;
use App\Enums\GeocodeLevel;
use App\Enums\Prefecture;
use App\Services\Abr\AbrDatasetClient;
use App\Services\Address\AddressMatchingNormalizer;
use App\Services\Address\MunicipalityResolver;
use RuntimeException;

/**
 * Locates facility addresses with the Address Base Registry, one
 * prefecture at a time. Each address is first parsed down to a 町字 and its
 * numbers; then each registry file is streamed once, keeping only the rows
 * some address needs, so that none of the (multi-million-row) files has to
 * be held in memory or in the database.
 *
 * The most precise location found wins: in a 住居表示 area the 住居
 * (〇番〇号), else its 街区 (〇番); elsewhere the 地番 with its 枝番, else
 * any 枝番 of the same 地番; and failing all of those the 町字's
 * representative point (or its 大字's, for a 小字 without one). 地番 positions exist only where the Ministry of
 * Justice's map has been digitized, so in many rural areas the 町字 is as
 * precise as the open data goes.
 *
 * @phpstan-type Target array{lg_code: string, town: string, parent_town: ?string, residence: ?string, block: ?string, parcel: ?string, parcel_base: ?string}
 */
final class FacilityGeocoder
{
    public function __construct(
        private readonly AbrDatasetClient $client,
        private readonly MunicipalityResolver $municipalityResolver,
        private readonly AddressMatchingNormalizer $normalizer,
        private readonly BanchiParser $banchiParser,
    ) {}

    /**
     * @param  array<int, string>  $addresses  facility id => address, all in $prefecture
     * @return array<int, GeocodeResult|null> facility id => location, null when not even the 町字 was found
     */
    public function geocode(Prefecture $prefecture, array $addresses): array
    {
        $targets = $this->parse($prefecture, $addresses);
        $located = array_filter($targets);

        $townPositions = $this->positions(AbrDataset::TownPosition, $prefecture->value, $this->keys($located, fn (array $target): array => [$target['town'], $target['parent_town']]), ['lg_code', 'machiaza_id']);
        $blockPositions = $this->numberedPositions(AbrDataset::Block, AbrDataset::BlockPosition, $prefecture->value, $located, 'block');
        $residencePositions = $this->numberedPositions(AbrDataset::Residence, AbrDataset::ResidencePosition, $prefecture->value, $located, 'residence');
        [$parcelPositions, $parcelBasePositions] = $this->parcelPositions($located);

        return array_map(function (?array $target) use ($townPositions, $blockPositions, $residencePositions, $parcelPositions, $parcelBasePositions): ?GeocodeResult {
            if ($target === null) {
                return null;
            }

            $at = fn (array $positions, ?string $key): ?array => $key === null ? null : ($positions[$key] ?? null);
            $candidates = [
                [GeocodeLevel::Residence, $at($residencePositions, $target['residence'])],
                [GeocodeLevel::Block, $at($blockPositions, $target['block'])],
                [GeocodeLevel::Parcel, $at($parcelPositions, $target['parcel'])],
                [GeocodeLevel::ParcelBase, $at($parcelBasePositions, $target['parcel_base'])],
                [GeocodeLevel::Town, $at($townPositions, $target['town'])],
                [GeocodeLevel::Town, $at($townPositions, $target['parent_town'])],
            ];

            foreach ($candidates as [$level, $position]) {
                if ($position !== null) {
                    return new GeocodeResult($level, $position[0], $position[1]);
                }
            }

            return null;
        }, $targets);
    }

    /**
     * Parses each address into the registry keys it could be located by.
     * A key is null where it does not apply (e.g. no 住居 key outside
     * 住居表示 areas).
     *
     * @param  array<int, string>  $addresses
     * @return array<int, Target|null>
     */
    private function parse(Prefecture $prefecture, array $addresses): array
    {
        $targets = array_fill_keys(array_keys($addresses), null);
        $remaindersByMunicipality = [];

        foreach ($addresses as $id => $address) {
            $municipality = $this->municipalityResolver->match($prefecture->value, $address);

            if ($municipality !== null) {
                $remaindersByMunicipality[$municipality['code']][$id] = $municipality['remainder'];
            }
        }

        // 町字 are read one municipality at a time and dropped once its
        // facilities are parsed: a whole prefecture's 町字 (86,000 rows in
        // Fukushima, mostly 小字) with their indexes does not fit in PHP's
        // default 128MB. The file lists each municipality's rows together.
        $currentLgCode = null;
        $towns = [];
        $done = [];

        foreach ($this->client->rows($this->requiredFile(AbrDataset::Town, $prefecture->value)) as $row) {
            if ($row['lg_code'] !== $currentLgCode) {
                if ($currentLgCode !== null) {
                    $this->parseMunicipality($currentLgCode, $towns, $remaindersByMunicipality[substr($currentLgCode, 0, 5)] ?? [], $targets);
                    $done[$currentLgCode] = true;
                    $towns = [];
                }

                if (isset($done[$row['lg_code']])) {
                    throw new RuntimeException("The 町字 rows of {$row['lg_code']} are not listed together in the {$prefecture->label()} file.");
                }

                $currentLgCode = $row['lg_code'];
            }

            if (($row['ablt_date'] ?? '') === '' && isset($remaindersByMunicipality[substr($currentLgCode, 0, 5)])) {
                $towns[] = array_intersect_key($row, array_flip(['machiaza_id', 'oaza_cho', 'chome_number', 'koaza', 'rsdt_addr_flg']));
            }
        }

        if ($currentLgCode !== null) {
            $this->parseMunicipality($currentLgCode, $towns, $remaindersByMunicipality[substr($currentLgCode, 0, 5)] ?? [], $targets);
        }

        return $targets;
    }

    /**
     * @param  list<array<string, string>>  $towns  mt_town rows of the municipality
     * @param  array<int, string>  $remainders  facility id => address after the municipality name
     * @param  array<int, Target|null>  $targets
     */
    private function parseMunicipality(string $lgCode, array $towns, array $remainders, array &$targets): void
    {
        if ($remainders === []) {
            return;
        }

        $matcher = new TownMatcher($towns, $this->normalizer);

        foreach ($remainders as $id => $remainder) {
            $town = $matcher->match($remainder);

            if ($town === null) {
                continue;
            }

            [$first, $second] = $this->banchiParser->parse($town['rest']) ?? [null, null];
            $townKey = $this->key($lgCode, $town['id']);
            $residential = $town['residential'];

            // 小字 rarely have a position of their own; their 大字 (same first
            // four digits of machiaza_id, then 000) usually does.
            $parentTownKey = $this->key($lgCode, substr($town['id'], 0, 4).'000');

            $targets[$id] = [
                'lg_code' => $lgCode,
                'town' => $townKey,
                'parent_town' => $parentTownKey !== $townKey ? $parentTownKey : null,
                'residence' => $residential && $second !== null ? $this->key($townKey, $first, $second) : null,
                'block' => $residential && $first !== null ? $this->key($townKey, $first) : null,
                'parcel' => ! $residential && $second !== null ? $this->key($townKey, $first, $second) : null,
                'parcel_base' => ! $residential && $first !== null ? $this->key($townKey, $first) : null,
            ];
        }
    }

    /**
     * Positions of 街区 or 住居: the master file maps their numbers (as
     * written in addresses) to registry ids, the position file maps the
     * ids to coordinates.
     *
     * @param  array<int, Target>  $targets
     * @param  'block'|'residence'  $kind
     * @return array<string, array{float, float}> key => [latitude, longitude]
     */
    private function numberedPositions(AbrDataset $master, AbrDataset $positions, string $prefectureCode, array $targets, string $kind): array
    {
        $wanted = $this->keys($targets, fn (array $target): array => [$target[$kind]]);

        if ($wanted === []) {
            return [];
        }

        $file = $this->client->fetch($master, $prefectureCode);
        $idColumns = $kind === 'block' ? ['lg_code', 'machiaza_id', 'blk_id'] : ['lg_code', 'machiaza_id', 'blk_id', 'rsdt_id'];
        $numberColumns = $kind === 'block' ? ['lg_code', 'machiaza_id', 'blk_num'] : ['lg_code', 'machiaza_id', 'blk_num', 'rsdt_num'];
        $keysById = [];

        foreach ($file !== null ? $this->client->rows($file) : [] as $row) {
            // 住居 with a sub-number (rsdt2) are a finer level than addresses go.
            if (($row['rsdt2_id'] ?? '') !== '' || ($row['ablt_date'] ?? '') !== '') {
                continue;
            }

            // Numbers are compared without leading zeros, as BanchiParser returns them.
            $key = $this->key(...array_map(
                fn (string $column): string => str_ends_with($column, '_num') ? (ltrim($row[$column], '0') ?: '0') : $row[$column],
                $numberColumns,
            ));

            if (isset($wanted[$key])) {
                $keysById[$this->key(...array_map(fn (string $column): string => $row[$column], $idColumns))] = $key;
            }
        }

        $byId = $this->positions($positions, $prefectureCode, $keysById, $idColumns, skipSubNumbers: true);
        $result = [];

        foreach ($byId as $id => $position) {
            $result[$keysById[$id]] = $position;
        }

        return $result;
    }

    /**
     * 地番 positions, read per municipality: exact (地番 + 枝番) and, for
     * when the exact 枝番 has no position, the first 枝番 of the same 地番
     * that has one.
     *
     * @param  array<int, Target>  $targets
     * @return array{0: array<string, array{float, float}>, 1: array<string, array{float, float}>}
     */
    private function parcelPositions(array $targets): array
    {
        $wantedByMunicipality = [];

        foreach ($targets as $target) {
            if ($target['parcel_base'] !== null) {
                $wantedByMunicipality[$target['lg_code']][$target['parcel_base']] = true;
            }
        }

        $exact = [];
        $base = [];

        foreach ($wantedByMunicipality as $lgCode => $wanted) {
            $file = $this->client->fetch(AbrDataset::Parcel, $lgCode);
            $parcelsById = [];

            foreach ($file !== null ? $this->client->rows($file) : [] as $row) {
                // machiaza_id is unique only within a municipality, so keys carry the lg_code.
                $baseKey = $this->key($lgCode, $row['machiaza_id'], ltrim($row['prc_num1'], '0') ?: '0');

                if (isset($wanted[$baseKey]) && ($row['ablt_date'] ?? '') === '') {
                    $branch = $row['prc_num2'] === '' ? null : (ltrim($row['prc_num2'], '0') ?: '0');
                    $parcelsById[$this->key($row['machiaza_id'], $row['prc_id'])] = [$baseKey, $branch === null ? null : $this->key($baseKey, $branch)];
                }
            }

            if ($parcelsById === []) {
                continue;
            }

            $positions = $this->positions(AbrDataset::ParcelPosition, $lgCode, $parcelsById, ['machiaza_id', 'prc_id']);
            ksort($positions);

            foreach ($positions as $id => $position) {
                [$baseKey, $exactKey] = $parcelsById[$id];
                $base[$baseKey] ??= $position;

                if ($exactKey !== null) {
                    $exact[$exactKey] ??= $position;
                }
            }
        }

        return [$exact, $base];
    }

    /**
     * Coordinates of the rows of a position file whose key is wanted.
     *
     * @param  array<string, mixed>  $wanted  key => anything
     * @param  list<string>  $keyColumns
     * @return array<string, array{float, float}> key => [latitude, longitude]
     */
    private function positions(AbrDataset $dataset, string $area, array $wanted, array $keyColumns, bool $skipSubNumbers = false): array
    {
        if ($wanted === []) {
            return [];
        }

        // Every prefecture has 町字 positions; a missing file means something
        // is wrong, not that no address can be located.
        $file = $dataset === AbrDataset::TownPosition ? $this->requiredFile($dataset, $area) : $this->client->fetch($dataset, $area);
        $positions = [];

        foreach ($file !== null ? $this->client->rows($file) : [] as $row) {
            if (($skipSubNumbers && ($row['rsdt2_id'] ?? '') !== '') || $row['rep_lat'] === '' || $row['rep_lon'] === '') {
                continue;
            }

            $key = $this->key(...array_map(fn (string $column): string => $row[$column], $keyColumns));

            if (isset($wanted[$key])) {
                $positions[$key] ??= [(float) $row['rep_lat'], (float) $row['rep_lon']];
            }
        }

        return $positions;
    }

    /**
     * A file every prefecture has. Without it every facility of the
     * prefecture would be recorded as unlocatable (and, with --all, lose
     * its location), so its absence fails the prefecture instead.
     */
    private function requiredFile(AbrDataset $dataset, string $area): string
    {
        return $this->client->fetch($dataset, $area)
            ?? throw new RuntimeException("The Address Base Registry has no {$dataset->value} file for \"{$area}\".");
    }

    /**
     * @param  array<int, Target>  $targets
     * @param  callable(Target): list<?string>  $keysOf
     * @return array<string, true>
     */
    private function keys(array $targets, callable $keysOf): array
    {
        $keys = [];

        foreach ($targets as $target) {
            foreach ($keysOf($target) as $key) {
                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    private function key(?string ...$parts): string
    {
        return implode('|', $parts);
    }
}
