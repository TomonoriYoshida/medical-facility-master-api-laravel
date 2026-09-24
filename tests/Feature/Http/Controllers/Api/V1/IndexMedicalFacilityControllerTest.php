<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\KanjiVariant;
use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndexMedicalFacilityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_paginated_facilities_with_enum_codes_and_japanese_labels(): void
    {
        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'bureau_code' => RhbBureau::Hokkaido,
        ]);

        $response = $this->getJson('/api/v1/medical-facilities');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $facility->id);
        $response->assertJsonPath('data.0.institution_type', ['code' => 1, 'label' => '病院']);
        $response->assertJsonPath('data.0.status', ['code' => 1, 'label' => '指定中']);
        $response->assertJsonPath('data.0.bureau', ['code' => 1, 'label' => '北海道厚生局']);
    }

    public function test_a_returned_enum_code_can_be_fed_back_as_the_corresponding_filter(): void
    {
        $clinic = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'bureau_code' => RhbBureau::Tohoku,
            'department_categories' => [DepartmentBaseCategory::Dermatology],
        ]);
        MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'bureau_code' => RhbBureau::Hokkaido,
            'department_categories' => [DepartmentBaseCategory::Surgery],
        ]);

        $shown = $this->getJson("/api/v1/medical-facilities/{$clinic->id}")->json('data');

        $response = $this->getJson('/api/v1/medical-facilities?'.http_build_query([
            'institution_type' => $shown['institution_type']['code'],
            'status' => $shown['status']['code'],
            'bureau_code' => $shown['bureau']['code'],
            'department_category' => $shown['department_categories'][0]['code'],
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $clinic->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPrefectureCodeProvider(): array
    {
        return [
            'zero' => ['00'],
            'above 47' => ['48'],
            'non-numeric' => ['ab'],
            'single digit' => ['1'],
        ];
    }

    #[DataProvider('invalidPrefectureCodeProvider')]
    public function test_returns_422_when_prefecture_code_is_not_a_valid_jis_code(string $prefectureCode): void
    {
        $response = $this->getJson('/api/v1/medical-facilities?prefecture_code='.$prefectureCode);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('prefecture_code');
    }

    public function test_response_includes_attribution_meta_for_all_eight_bureaus(): void
    {
        MedicalFacility::factory()->create();

        $response = $this->getJson('/api/v1/medical-facilities');

        $response->assertOk();
        $response->assertJsonPath('meta.attribution.notice', '本APIのデータは、各地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を加工して作成しています。');
        $response->assertJsonCount(8, 'meta.attribution.sources');
        $response->assertJsonPath('meta.attribution.sources.0.bureau', '北海道厚生局');
    }

    public function test_filters_by_prefecture_code(): void
    {
        $matching = MedicalFacility::factory()->create(['prefecture_code' => '01']);
        MedicalFacility::factory()->create(['prefecture_code' => '13']);

        $response = $this->getJson('/api/v1/medical-facilities?prefecture_code=01');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_filters_by_institution_type(): void
    {
        $matching = MedicalFacility::factory()->create(['institution_type' => InstitutionType::Pharmacy]);
        MedicalFacility::factory()->create(['institution_type' => InstitutionType::Hospital]);

        $response = $this->getJson('/api/v1/medical-facilities?institution_type='.InstitutionType::Pharmacy->value);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_filters_by_status(): void
    {
        $matching = MedicalFacility::factory()->create(['status' => MedicalFacilityStatus::Suspended]);
        MedicalFacility::factory()->create(['status' => MedicalFacilityStatus::Active]);

        $response = $this->getJson('/api/v1/medical-facilities?status='.MedicalFacilityStatus::Suspended->value);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_filters_by_bureau_code(): void
    {
        $matching = MedicalFacility::factory()->create(['bureau_code' => RhbBureau::Kyushu]);
        MedicalFacility::factory()->create(['bureau_code' => RhbBureau::Hokkaido]);

        $response = $this->getJson('/api/v1/medical-facilities?bureau_code='.RhbBureau::Kyushu->value);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_filters_by_department_category(): void
    {
        $matching = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'department_categories' => [DepartmentBaseCategory::Cardiology],
        ]);
        MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'department_categories' => [DepartmentBaseCategory::Dermatology],
        ]);

        $response = $this->getJson('/api/v1/medical-facilities?department_category='.DepartmentBaseCategory::Cardiology->value);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_search_matches_facility_name_across_itaiji_variants(): void
    {
        KanjiVariant::create([
            'variant_character' => '髙',
            'canonical_character' => '高',
            'source' => 'manual',
        ]);
        $matching = MedicalFacility::factory()->create(['name' => '髙橋病院']);
        MedicalFacility::factory()->create(['name' => '山田病院']);

        $response = $this->getJson('/api/v1/medical-facilities?q='.urlencode('高橋'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_search_matches_address_ignoring_full_width_digit_differences(): void
    {
        $matching = MedicalFacility::factory()->create(['address' => '世田谷区池尻１５７ー１']);
        MedicalFacility::factory()->create(['address' => '中央区銀座1丁目']);

        $response = $this->getJson('/api/v1/medical-facilities?q='.urlencode('157-1'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function likeMetacharacterProvider(): array
    {
        return [
            'percent' => ['%', '100%クリニック'],
            'underscore' => ['_', 'A_B病院'],
            'backslash' => ['\\', 'C\\D病院'],
            'full-width percent (normalized to %)' => ['％', '100%クリニック'],
            'full-width underscore (normalized to _)' => ['＿', 'A_B病院'],
        ];
    }

    #[DataProvider('likeMetacharacterProvider')]
    public function test_search_treats_like_metacharacters_literally(string $term, string $matchingName): void
    {
        $matching = MedicalFacility::factory()->create(['name' => $matchingName, 'address' => '札幌市中央区']);
        MedicalFacility::factory()->create(['name' => '山田病院', 'address' => '札幌市北区']);

        $response = $this->getJson('/api/v1/medical-facilities?q='.urlencode($term));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_underscore_does_not_match_an_arbitrary_single_character(): void
    {
        MedicalFacility::factory()->create(['name' => 'AXB病院', 'address' => '札幌市中央区']);

        $response = $this->getJson('/api/v1/medical-facilities?q='.urlencode('A_B'));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUtf8SearchTermProvider(): array
    {
        return [
            'lone invalid byte' => ['%FF'],
            'truncated multibyte character' => ['%E3%81'],
        ];
    }

    /**
     * Invalid UTF-8 used to reach AddressNormalizer, whose preg_replace()
     * returns null for it, and surface as a 500 TypeError.
     */
    #[DataProvider('invalidUtf8SearchTermProvider')]
    public function test_returns_422_when_q_is_not_valid_utf8(string $encodedTerm): void
    {
        $response = $this->getJson('/api/v1/medical-facilities?q='.$encodedTerm);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('q');
    }

    public function test_returns_422_when_institution_type_is_not_a_valid_enum_value(): void
    {
        $response = $this->getJson('/api/v1/medical-facilities?institution_type=999');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['institution_type']);
    }

    public function test_per_page_limits_the_number_of_returned_facilities(): void
    {
        MedicalFacility::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/medical-facilities?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_returns_422_when_per_page_exceeds_the_maximum(): void
    {
        $response = $this->getJson('/api/v1/medical-facilities?per_page=101');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['per_page']);
    }
}
