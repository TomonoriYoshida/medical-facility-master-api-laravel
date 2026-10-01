<?php

namespace App\Services\Address;

use App\Enums\Prefecture;
use App\Models\Municipality;

final class MunicipalityResolver
{
    /** @var array<string, array<string, string>> prefecture code => 正規化した市区町村名 => 市区町村コード（名前の長い順） */
    private array $namesByPrefecture = [];

    public function __construct(private readonly AddressMatchingNormalizer $normalizer) {}

    /**
     * Finds the municipality an address starts with. Returns null when no
     * municipality matches.
     */
    public function resolve(string $prefectureCode, string $address): ?string
    {
        return $this->match($prefectureCode, $address)['code'] ?? null;
    }

    /**
     * Finds the municipality an address starts with, and returns the rest
     * of the address after it (in AddressMatchingNormalizer's spelling).
     * Addresses in the source data omit the prefecture, so the match is
     * scoped to $prefectureCode, and the longest name wins so that
     * 札幌市中央区 beats 札幌市 and 大町町 beats 大町.
     *
     * @return array{code: string, remainder: string}|null
     */
    public function match(string $prefectureCode, string $address): ?array
    {
        $names = $this->names($prefectureCode);
        $address = $this->normalizer->normalize($address);
        $prefectureLabel = Prefecture::tryFrom($prefectureCode)?->label();

        if ($prefectureLabel !== null && str_starts_with($address, $prefectureLabel)) {
            $address = substr($address, strlen($prefectureLabel));
        }

        $match = $this->longestPrefixMatch($names, $address);

        // 離島の住所は「八丈島八丈町…」のように島名が市区町村名の前に付く。
        if ($match === null && ($position = mb_strpos($address, '島')) !== false && $position < 6) {
            $match = $this->longestPrefixMatch($names, mb_substr($address, $position + 1));
        }

        return $match;
    }

    /**
     * Display name of a 5-digit municipality code, or null if unknown.
     */
    public function label(string $code): ?string
    {
        return once(fn (): array => Municipality::query()->pluck('name', 'code')->all())[$code] ?? null;
    }

    /**
     * @param  array<string, string>  $names
     * @return array{code: string, remainder: string}|null
     */
    private function longestPrefixMatch(array $names, string $address): ?array
    {
        foreach ($names as $name => $code) {
            if (str_starts_with($address, $name)) {
                return ['code' => $code, 'remainder' => substr($address, strlen($name))];
            }
        }

        return null;
    }

    /**
     * Each municipality is indexed by its full name (郡を含む) and, when
     * that is unambiguous within the prefecture, by its name without the
     * 郡, since addresses often leave the 郡 out.
     *
     * @return array<string, string>
     */
    private function names(string $prefectureCode): array
    {
        return $this->namesByPrefecture[$prefectureCode] ??= $this->buildNames($prefectureCode);
    }

    /**
     * @return array<string, string>
     */
    private function buildNames(string $prefectureCode): array
    {
        $names = [];
        $withoutCounty = [];

        foreach (Municipality::query()->where('prefecture_code', $prefectureCode)->pluck('name', 'code') as $code => $name) {
            $name = $this->normalizer->normalize($name);
            $names[$name] = (string) $code;

            if (preg_match('/^.+?郡(.+)$/u', $name, $matches) === 1) {
                $withoutCounty[$matches[1]][] = (string) $code;
            }
        }

        foreach ($withoutCounty as $name => $codes) {
            if (count($codes) === 1 && ! isset($names[$name])) {
                $names[$name] = $codes[0];
            }
        }

        uksort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }
}
