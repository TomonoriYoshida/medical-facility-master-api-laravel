<?php

namespace Tests\Feature\Services\Rhb;

use App\Enums\InstitutionType;
use App\Enums\Prefecture;
use App\Enums\RhbCategory;
use App\Services\Rhb\RhbScope;
use InvalidArgumentException;
use Tests\TestCase;

class RhbScopeTest extends TestCase
{
    public function test_an_empty_scope_covers_the_whole_country_and_every_category(): void
    {
        config(['rhb.scope.prefectures' => [], 'rhb.scope.categories' => []]);

        $scope = new RhbScope;

        $this->assertSame(Prefecture::cases(), $scope->prefectures());
        $this->assertSame(RhbCategory::cases(), $scope->categories());
        $this->assertCount(8, $scope->bureaus());
    }

    public function test_only_bureaus_covering_a_selected_prefecture_are_crawled_even_when_far_apart(): void
    {
        config(['rhb.scope.prefectures' => ['39', '02']]);

        $scope = new RhbScope;

        $this->assertSame(['tohoku', 'shikoku'], array_keys($scope->bureaus()));
        $this->assertSame([Prefecture::Aomori, Prefecture::Kochi], $scope->prefectures());
        $this->assertTrue($scope->includesPrefecture('02'));
        $this->assertFalse($scope->includesPrefecture('03'));
    }

    public function test_categories_select_their_institution_types(): void
    {
        config(['rhb.scope.categories' => ['pharmacy', 'medical']]);

        $scope = new RhbScope;

        $this->assertSame([RhbCategory::Medical, RhbCategory::Pharmacy], $scope->categories());
        $this->assertSame([InstitutionType::Hospital, InstitutionType::Clinic, InstitutionType::Pharmacy], $scope->institutionTypes());
        $this->assertFalse($scope->includesCategory(RhbCategory::Dental));
    }

    public function test_an_unknown_prefecture_code_is_rejected(): void
    {
        config(['rhb.scope.prefectures' => ['02', '48']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"48"');

        new RhbScope;
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        config(['rhb.scope.categories' => ['hospital']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"hospital"');

        new RhbScope;
    }
}
