<?php

namespace App\Services\Rhb;

use App\Enums\InstitutionType;
use App\Enums\Prefecture;
use App\Enums\RhbCategory;
use InvalidArgumentException;

/**
 * The prefectures and categories this installation tracks (RHB_PREFECTURES /
 * RHB_CATEGORIES, both "everything" when empty), so an operator who only
 * needs part of the country neither crawls nor stores the rest. Any
 * combination is allowed, including prefectures far apart.
 *
 * Bureaus are derived rather than configured: one is crawled only when it
 * covers an in-scope prefecture. Within a crawled bureau, most publish every
 * prefecture bundled into one file, so the file is still downloaded, but
 * only in-scope prefectures are imported.
 */
final class RhbScope
{
    /** @var list<Prefecture> */
    private readonly array $prefectures;

    /** @var list<RhbCategory> */
    private readonly array $categories;

    public function __construct()
    {
        $this->prefectures = $this->parsePrefectures(config()->array('rhb.scope.prefectures'));
        $this->categories = $this->parseCategories(config()->array('rhb.scope.categories'));
    }

    public function includesPrefecture(string $prefectureCode): bool
    {
        return in_array(Prefecture::tryFrom($prefectureCode), $this->prefectures, true);
    }

    public function includesCategory(RhbCategory $category): bool
    {
        return in_array($category, $this->categories, true);
    }

    /**
     * @return list<Prefecture>
     */
    public function prefectures(): array
    {
        return $this->prefectures;
    }

    /**
     * @return list<RhbCategory>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    /**
     * @return list<InstitutionType>
     */
    public function institutionTypes(): array
    {
        return array_merge(...array_map(fn (RhbCategory $category): array => $category->institutionTypes(), $this->categories));
    }

    /**
     * config('rhb.bureaus') entries covering at least one in-scope prefecture,
     * keyed by bureau key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bureaus(): array
    {
        $prefectureCodes = array_map(fn (Prefecture $prefecture): string => $prefecture->value, $this->prefectures);

        return array_filter(
            config()->array('rhb.bureaus'),
            fn (string $key): bool => array_intersect(config()->array("rhb.bureaus.{$key}.prefecture_codes"), $prefectureCodes) !== [],
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<mixed>  $codes
     * @return list<Prefecture>
     */
    private function parsePrefectures(array $codes): array
    {
        if ($codes === []) {
            return Prefecture::cases();
        }

        $prefectures = [];

        foreach ($codes as $code) {
            $prefectures[] = Prefecture::tryFrom((string) $code)
                ?? throw new InvalidArgumentException("RHB_PREFECTURES: unknown prefecture code \"{$code}\" (expected 01-47).");
        }

        return array_values(array_filter(Prefecture::cases(), fn (Prefecture $prefecture): bool => in_array($prefecture, $prefectures, true)));
    }

    /**
     * @param  array<mixed>  $keys
     * @return list<RhbCategory>
     */
    private function parseCategories(array $keys): array
    {
        if ($keys === []) {
            return RhbCategory::cases();
        }

        $categories = [];

        foreach ($keys as $key) {
            $categories[] = collect(RhbCategory::cases())->first(fn (RhbCategory $category): bool => $category->key() === $key)
                ?? throw new InvalidArgumentException("RHB_CATEGORIES: unknown category \"{$key}\" (expected medical, dental or pharmacy).");
        }

        return array_values(array_filter(RhbCategory::cases(), fn (RhbCategory $category): bool => in_array($category, $categories, true)));
    }
}
