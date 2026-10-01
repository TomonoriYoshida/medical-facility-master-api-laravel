<?php

namespace App\Services\Address;

use App\Enums\Prefecture;
use App\Models\Municipality;
use App\Services\Text\ItaijiNormalizer;

final class MunicipalityResolver
{
    /** @var array<string, array<string, string>> prefecture code => 正規化した市区町村名 => 市区町村コード（名前の長い順） */
    private array $namesByPrefecture = [];

    public function __construct(private readonly ItaijiNormalizer $itaijiNormalizer) {}

    /**
     * Finds the municipality an address starts with. Addresses in the
     * source data omit the prefecture, so the match is scoped to
     * $prefectureCode, and the longest name wins so that 札幌市中央区 beats
     * 札幌市 and 大町町 beats 大町. Names are compared in the same
     * normalized spelling as the address (全角半角・異体字・ケ/ヶ).
     *
     * Returns null when no municipality matches.
     */
    public function resolve(string $prefectureCode, string $address): ?string
    {
        $names = $this->names($prefectureCode);
        $address = $this->normalize($address);
        $prefectureLabel = Prefecture::tryFrom($prefectureCode)?->label();

        if ($prefectureLabel !== null && str_starts_with($address, $prefectureLabel)) {
            $address = substr($address, strlen($prefectureLabel));
        }

        $code = $this->longestPrefixMatch($names, $address);

        // 離島の住所は「八丈島八丈町…」のように島名が市区町村名の前に付く。
        if ($code === null && ($position = mb_strpos($address, '島')) !== false && $position < 6) {
            $code = $this->longestPrefixMatch($names, mb_substr($address, $position + 1));
        }

        return $code;
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
     */
    private function longestPrefixMatch(array $names, string $address): ?string
    {
        foreach ($names as $name => $code) {
            if (str_starts_with($address, $name)) {
                return $code;
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
            $name = $this->normalize($name);
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

    private function normalize(string $value): string
    {
        $value = $this->itaijiNormalizer->normalize($value);

        return str_replace('ケ', 'ヶ', $value);
    }
}
