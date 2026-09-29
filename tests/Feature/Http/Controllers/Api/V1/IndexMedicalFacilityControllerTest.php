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
        $response->assertJsonPath('meta.attribution.license.name', '公共データ利用規約（第1.0版）');
        $response->assertJsonPath('meta.attribution.license.url', 'https://www.digital.go.jp/resources/open_data/public_data_license_v1.0');
        $response->assertJsonPath('meta.attribution.disclaimer', 'データの正確性・完全性は保証しません。最新かつ正確な情報は、各地方厚生局の公表資料を確認してください。');
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

    public function test_filters_by_designation_date_range_inclusive_of_both_ends(): void
    {
        $onFirstDay = MedicalFacility::factory()->create(['designated_on' => '2026-08-01']);
        $onLastDay = MedicalFacility::factory()->create(['designated_on' => '2026-08-31']);
        MedicalFacility::factory()->create(['designated_on' => '2026-07-31']);
        MedicalFacility::factory()->create(['designated_on' => '2026-09-01']);

        $response = $this->getJson('/api/v1/medical-facilities?designated_from=2026-08-01&designated_to=2026-08-31');

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$onFirstDay->id, $onLastDay->id], $response->json('data.*.id'));
    }

    public function test_designated_from_alone_has_no_upper_bound(): void
    {
        $recent = MedicalFacility::factory()->create(['designated_on' => '2026-09-01']);
        MedicalFacility::factory()->create(['designated_on' => '1985-04-01']);

        $response = $this->getJson('/api/v1/medical-facilities?designated_from=2026-01-01');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_filters_by_designation_reason(): void
    {
        $newlyOpened = MedicalFacility::factory()->create([
            'designation_history' => [['reason' => '新規', 'date' => '2026-08-01']],
        ]);
        MedicalFacility::factory()->create([
            'designation_history' => [['reason' => '交代', 'date' => '2026-08-01']],
        ]);
        MedicalFacility::factory()->create(['designation_history' => []]);

        $response = $this->getJson('/api/v1/medical-facilities?designation_reason='.urlencode('新規'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $newlyOpened->id);
    }

    public function test_sorts_by_designation_date_newest_first_with_id_as_tiebreaker(): void
    {
        $oldest = MedicalFacility::factory()->create(['designated_on' => '2001-01-01']);
        $newestA = MedicalFacility::factory()->create(['designated_on' => '2026-09-01']);
        $newestB = MedicalFacility::factory()->create(['designated_on' => '2026-09-01']);
        $undated = MedicalFacility::factory()->create(['designated_on' => null]);

        $response = $this->getJson('/api/v1/medical-facilities?sort=-designated_on');

        $response->assertOk();
        $this->assertSame([$newestA->id, $newestB->id, $oldest->id, $undated->id], $response->json('data.*.id'));
    }

    public function test_sorts_by_designation_date_oldest_first(): void
    {
        $newest = MedicalFacility::factory()->create(['designated_on' => '2026-09-01']);
        $oldest = MedicalFacility::factory()->create(['designated_on' => '2001-01-01']);

        $response = $this->getJson('/api/v1/medical-facilities?sort=designated_on');

        $response->assertOk();
        $this->assertSame([$oldest->id, $newest->id], $response->json('data.*.id'));
    }

    /**
     * @return array<string, array{InstitutionType, string}>
     */
    public static function scoreTableNumberProvider(): array
    {
        return [
            'hospital (医科)' => [InstitutionType::Hospital, '1311012345'],
            'clinic (医科)' => [InstitutionType::Clinic, '1311012345'],
            'dental clinic (歯科)' => [InstitutionType::DentalClinic, '1331012345'],
            'pharmacy (薬局)' => [InstitutionType::Pharmacy, '1341012345'],
        ];
    }

    /**
     * The nationally unique 10-digit code: prefecture (2) + score table
     * number (1: 医科, 3: 歯科, 4: 薬局) + the bureau's 7-digit code.
     */
    #[DataProvider('scoreTableNumberProvider')]
    public function test_returns_the_ten_digit_medical_institution_code(InstitutionType $institutionType, string $expectedCode): void
    {
        MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'institution_type' => $institutionType,
            'facility_code' => '1012345',
        ]);

        $response = $this->getJson('/api/v1/medical-facilities');

        $response->assertOk();
        $response->assertJsonPath('data.0.medical_institution_code', $expectedCode);
    }

    public function test_filters_by_one_or_more_medical_institution_codes(): void
    {
        $tokyoClinic = MedicalFacility::factory()->create(['prefecture_code' => '13', 'institution_type' => InstitutionType::Clinic, 'facility_code' => '1012345']);
        $tokyoPharmacy = MedicalFacility::factory()->create(['prefecture_code' => '13', 'institution_type' => InstitutionType::Pharmacy, 'facility_code' => '1012345']);
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'institution_type' => InstitutionType::DentalClinic, 'facility_code' => '1012345']);

        $single = $this->getJson('/api/v1/medical-facilities?medical_institution_code=1311012345');
        $multiple = $this->getJson('/api/v1/medical-facilities?medical_institution_code=1311012345,1341012345');

        $single->assertOk();
        $this->assertSame([$tokyoClinic->id], $single->json('data.*.id'));
        $multiple->assertOk();
        $this->assertEqualsCanonicalizing([$tokyoClinic->id, $tokyoPharmacy->id], $multiple->json('data.*.id'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMedicalInstitutionCodeProvider(): array
    {
        return [
            'seven digits' => ['1012345'],
            'non-numeric' => ['13101234ab'],
            'empty item' => ['1311012345,'],
            'more than 100 codes' => [implode(',', array_fill(0, 101, '1311012345'))],
        ];
    }

    #[DataProvider('invalidMedicalInstitutionCodeProvider')]
    public function test_returns_422_for_an_invalid_medical_institution_code(string $codes): void
    {
        $response = $this->getJson('/api/v1/medical-facilities?medical_institution_code='.$codes);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['medical_institution_code']);
    }

    public function test_updated_since_returns_facilities_changed_at_or_after_the_given_time(): void
    {
        $atTheInstant = MedicalFacility::factory()->create(['updated_at' => '2026-10-01 05:00:00']);
        $later = MedicalFacility::factory()->create(['updated_at' => '2026-10-02 05:10:00']);
        MedicalFacility::factory()->create(['updated_at' => '2026-10-01 04:59:59']);

        $response = $this->getJson('/api/v1/medical-facilities?'.http_build_query(['updated_since' => '2026-10-01T05:00:00Z']));

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$atTheInstant->id, $later->id], $response->json('data.*.id'));
    }

    public function test_updated_since_honors_the_given_utc_offset(): void
    {
        $changed = MedicalFacility::factory()->create(['updated_at' => '2026-10-01 05:00:00']);
        MedicalFacility::factory()->create(['updated_at' => '2026-10-01 04:00:00']);

        // 14:00 in Japan is 05:00 UTC, the timezone updated_at is stored in.
        $response = $this->getJson('/api/v1/medical-facilities?'.http_build_query(['updated_since' => '2026-10-01T14:00:00+09:00']));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $changed->id);
    }

    public function test_sorts_by_update_time_oldest_first_with_id_as_tiebreaker(): void
    {
        $latest = MedicalFacility::factory()->create(['updated_at' => '2026-10-03 05:00:00']);
        $earliestA = MedicalFacility::factory()->create(['updated_at' => '2026-10-01 05:00:00']);
        $earliestB = MedicalFacility::factory()->create(['updated_at' => '2026-10-01 05:00:00']);

        $response = $this->getJson('/api/v1/medical-facilities?sort=updated_at');

        $response->assertOk();
        $this->assertSame([$earliestA->id, $earliestB->id, $latest->id], $response->json('data.*.id'));
    }

    public function test_sorts_by_update_time_newest_first(): void
    {
        $earliest = MedicalFacility::factory()->create(['updated_at' => '2026-10-01 05:00:00']);
        $latest = MedicalFacility::factory()->create(['updated_at' => '2026-10-03 05:00:00']);

        $response = $this->getJson('/api/v1/medical-facilities?sort=-updated_at');

        $response->assertOk();
        $this->assertSame([$latest->id, $earliest->id], $response->json('data.*.id'));
    }

    public function test_returns_422_when_updated_since_is_not_a_date_time(): void
    {
        $response = $this->getJson('/api/v1/medical-facilities?updated_since=yesterday-ish');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['updated_since']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidDesignationParameterProvider(): array
    {
        return [
            'designated_from not a date' => [['designated_from' => '2026/08/01'], 'designated_from'],
            'designated_to not a date' => [['designated_to' => 'yesterday'], 'designated_to'],
            'designated_to before designated_from' => [['designated_from' => '2026-09-01', 'designated_to' => '2026-08-01'], 'designated_to'],
            'unknown sort' => [['sort' => 'name'], 'sort'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidDesignationParameterProvider')]
    public function test_returns_422_for_invalid_designation_parameters(array $query, string $invalidField): void
    {
        $response = $this->getJson('/api/v1/medical-facilities?'.http_build_query($query));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([$invalidField]);
    }
}
