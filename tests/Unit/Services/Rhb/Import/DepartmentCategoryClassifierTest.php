<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Enums\DepartmentBaseCategory;
use App\Services\Rhb\Import\DepartmentCategoryClassifier;
use PHPUnit\Framework\TestCase;

class DepartmentCategoryClassifierTest extends TestCase
{
    public function test_simple_single_character_abbreviations_are_classified(): void
    {
        $categories = (new DepartmentCategoryClassifier)->classify(['内', '外', '小']);

        $this->assertSame(
            [DepartmentBaseCategory::InternalMedicine, DepartmentBaseCategory::Surgery, DepartmentBaseCategory::Pediatrics],
            $categories,
        );
    }

    public function test_an_organ_system_compound_is_not_swallowed_by_the_generic_base_category(): void
    {
        // Regression test: "循環器内科" contains "内科" as a substring, so
        // a naive marker table checking generic 内科 before the more
        // specific 循環器 qualifier would wrongly classify this as
        // InternalMedicine instead of Cardiology.
        $categories = (new DepartmentCategoryClassifier)->classify(['循環器内科']);

        $this->assertSame([DepartmentBaseCategory::Cardiology], $categories);
    }

    public function test_two_character_abbreviations_resolve_to_the_same_category_as_the_full_name(): void
    {
        $categories = (new DepartmentCategoryClassifier)->classify(['呼内']);

        $this->assertSame([DepartmentBaseCategory::Respirology], $categories);
    }

    public function test_duplicate_categories_across_tokens_are_deduplicated(): void
    {
        $categories = (new DepartmentCategoryClassifier)->classify(['内科', '消化器内科', '内']);

        $this->assertSame(
            [DepartmentBaseCategory::InternalMedicine, DepartmentBaseCategory::Gastroenterology],
            $categories,
        );
    }

    public function test_an_unrecognized_token_is_silently_dropped(): void
    {
        $categories = (new DepartmentCategoryClassifier)->classify(['他']);

        $this->assertSame([], $categories);
    }

    public function test_a_real_dense_department_list_is_classified_without_error(): void
    {
        $tokens = ['内', '消化器内科', '循環器内科', '呼内', '脳内', 'リハ', '歯'];

        $categories = (new DepartmentCategoryClassifier)->classify($tokens);

        $this->assertContains(DepartmentBaseCategory::InternalMedicine, $categories);
        $this->assertContains(DepartmentBaseCategory::Gastroenterology, $categories);
        $this->assertContains(DepartmentBaseCategory::Cardiology, $categories);
        $this->assertContains(DepartmentBaseCategory::Respirology, $categories);
        $this->assertContains(DepartmentBaseCategory::Neurology, $categories);
        $this->assertContains(DepartmentBaseCategory::Rehabilitation, $categories);
        $this->assertContains(DepartmentBaseCategory::Dentistry, $categories);
    }
}
