<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\KanjiVariant;
use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexMedicalFacilityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_paginated_facilities_with_japanese_enum_labels(): void
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
        $response->assertJsonPath('data.0.institution_type', '病院');
        $response->assertJsonPath('data.0.status', '指定中');
        $response->assertJsonPath('data.0.bureau_code', '北海道厚生局');
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
