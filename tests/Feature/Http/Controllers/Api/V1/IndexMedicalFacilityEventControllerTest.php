<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventOrigin;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Sync\FacilityUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndexMedicalFacilityEventControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_changes_detected_between_publications(): void
    {
        $detected = $this->event(MedicalFacilityEventType::Created);
        $this->event(MedicalFacilityEventType::Created, ['origin' => MedicalFacilityEventOrigin::Baseline]);
        $this->event(MedicalFacilityEventType::Updated, ['origin' => MedicalFacilityEventOrigin::Reprocessed]);

        $response = $this->getJson('/api/v1/medical-facility-events');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $detected->id);
    }

    public function test_returns_the_event_with_codes_labels_and_its_facility(): void
    {
        $facility = MedicalFacility::factory()->create(['name' => '新しい診療所']);
        $this->event(MedicalFacilityEventType::Created, [
            'medical_facility_id' => $facility->id,
            'occurred_on' => '2026-10-01',
        ]);

        $response = $this->getJson('/api/v1/medical-facility-events');

        $response->assertOk();
        $response->assertJsonPath('data.0.event_type', ['code' => 1, 'label' => '新規']);
        $response->assertJsonPath('data.0.origin', ['code' => 2, 'label' => '検知']);
        $response->assertJsonPath('data.0.occurred_on', '2026-10-01');
        $response->assertJsonPath('data.0.is_reopening', false);
        $response->assertJsonPath('data.0.facility.id', $facility->id);
        $response->assertJsonPath('data.0.facility.name', '新しい診療所');
        $response->assertJsonPath('meta.attribution.license.name', '公共データ利用規約（第1.0版）');
    }

    public function test_a_created_event_after_an_earlier_removal_is_a_reopening(): void
    {
        $facility = MedicalFacility::factory()->create();
        $this->event(MedicalFacilityEventType::Removed, ['medical_facility_id' => $facility->id, 'occurred_on' => '2026-08-01']);
        $reopened = $this->event(MedicalFacilityEventType::Created, ['medical_facility_id' => $facility->id, 'occurred_on' => '2026-10-01']);

        $response = $this->getJson('/api/v1/medical-facility-events?event_type=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $reopened->id);
        $response->assertJsonPath('data.0.is_reopening', true);
    }

    public function test_changes_use_the_same_names_and_formats_as_the_facility_resource(): void
    {
        $this->event(MedicalFacilityEventType::Updated, ['payload' => [
            'status' => ['old' => 1, 'new' => 3],
            'bureau_code' => ['old' => 1, 'new' => 2],
            'designated_on' => ['old' => '2001-04-01T00:00:00.000000Z', 'new' => '2026-08-01'],
            'department_categories' => ['old' => [1], 'new' => [1, 5]],
            'phone_number' => ['old' => '011-111-1111', 'new' => '011-222-2222'],
        ]]);

        $response = $this->getJson('/api/v1/medical-facility-events');

        $response->assertOk();
        $response->assertJsonPath('data.0.changes', [
            ['attribute' => 'status', 'old' => ['code' => 1, 'label' => '指定中'], 'new' => ['code' => 3, 'label' => '休止']],
            ['attribute' => 'bureau', 'old' => ['code' => 1, 'label' => '北海道厚生局'], 'new' => ['code' => 2, 'label' => '東北厚生局']],
            ['attribute' => 'phone_number', 'old' => '011-111-1111', 'new' => '011-222-2222'],
            ['attribute' => 'designated_on', 'old' => '2001-04-01', 'new' => '2026-08-01'],
            ['attribute' => 'department_categories', 'old' => [['code' => 1, 'label' => '内科']], 'new' => [['code' => 1, 'label' => '内科'], ['code' => 5, 'label' => '眼科']]],
        ]);
    }

    public function test_changes_recorded_by_a_real_import_are_formatted(): void
    {
        $upserter = app(FacilityUpserter::class);
        $attributes = [
            'facility_code' => '0111000',
            'bureau_code' => RhbBureau::Hokkaido,
            'institution_type' => InstitutionType::Hospital,
            'status' => MedicalFacilityStatus::Active,
            'name' => 'テスト病院',
            'prefecture_code' => '01',
            'postal_code' => '060-0000',
            'address' => '札幌市中央区',
            'phone_number' => '011-000-0000',
            'founder_name' => 'テスト法人',
            'administrator_name' => 'テスト太郎',
            'designated_on' => '1990-01-01',
            'designation_history' => [],
            'bed_counts' => ['一般' => 100],
            'department_categories' => [DepartmentBaseCategory::InternalMedicine],
        ];
        $upserter->upsert($attributes, RhbDatasetDownload::factory()->create(['published_on' => '2026-09-01']));

        $upserter->upsert([
            ...$attributes,
            'status' => MedicalFacilityStatus::Suspended,
            'administrator_name' => '別の管理者',
            'designated_on' => '2026-08-01',
            'department_categories' => [DepartmentBaseCategory::InternalMedicine, DepartmentBaseCategory::Ophthalmology],
        ], RhbDatasetDownload::factory()->create(['published_on' => '2026-10-01']));

        $response = $this->getJson('/api/v1/medical-facility-events?event_type='.MedicalFacilityEventType::Updated->value);

        $response->assertOk();
        $response->assertJsonPath('data.0.changes', [
            ['attribute' => 'status', 'old' => ['code' => 1, 'label' => '指定中'], 'new' => ['code' => 3, 'label' => '休止']],
            ['attribute' => 'designated_on', 'old' => '1990-01-01', 'new' => '2026-08-01'],
            ['attribute' => 'department_categories', 'old' => [['code' => 1, 'label' => '内科']], 'new' => [['code' => 1, 'label' => '内科'], ['code' => 5, 'label' => '眼科']]],
        ]);
    }

    public function test_personal_name_changes_are_never_returned(): void
    {
        $this->event(MedicalFacilityEventType::Updated, ['payload' => [
            'administrator_name' => ['old' => '山田 太郎', 'new' => '佐藤 花子'],
            'name' => ['old' => '旧名称', 'new' => '新名称'],
        ]]);
        $this->event(MedicalFacilityEventType::Updated, ['payload' => [
            'founder_name' => ['old' => '山田 太郎', 'new' => '佐藤 花子'],
        ]]);

        $response = $this->getJson('/api/v1/medical-facility-events');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.changes', [
            ['attribute' => 'name', 'old' => '旧名称', 'new' => '新名称'],
        ]);
        $this->assertStringNotContainsString('山田', $response->getContent());
    }

    public function test_orders_newest_first_with_id_as_tiebreaker(): void
    {
        $older = $this->event(MedicalFacilityEventType::Created, ['occurred_on' => '2026-08-01']);
        $newestA = $this->event(MedicalFacilityEventType::Created, ['occurred_on' => '2026-10-01']);
        $newestB = $this->event(MedicalFacilityEventType::Removed, ['occurred_on' => '2026-10-01']);

        $response = $this->getJson('/api/v1/medical-facility-events');

        $response->assertOk();
        $this->assertSame([$newestB->id, $newestA->id, $older->id], $response->json('data.*.id'));
    }

    public function test_filters_by_event_type(): void
    {
        $removed = $this->event(MedicalFacilityEventType::Removed);
        $this->event(MedicalFacilityEventType::Created);

        $response = $this->getJson('/api/v1/medical-facility-events?event_type='.MedicalFacilityEventType::Removed->value);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $removed->id);
    }

    public function test_filters_by_occurrence_date_range_inclusive_of_both_ends(): void
    {
        $onFirstDay = $this->event(MedicalFacilityEventType::Created, ['occurred_on' => '2026-10-01']);
        $onLastDay = $this->event(MedicalFacilityEventType::Created, ['occurred_on' => '2026-10-31']);
        $this->event(MedicalFacilityEventType::Created, ['occurred_on' => '2026-09-30']);
        $this->event(MedicalFacilityEventType::Created, ['occurred_on' => '2026-11-01']);

        $response = $this->getJson('/api/v1/medical-facility-events?occurred_from=2026-10-01&occurred_to=2026-10-31');

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$onFirstDay->id, $onLastDay->id], $response->json('data.*.id'));
    }

    public function test_filters_by_detection_time_regardless_of_the_publication_date(): void
    {
        // Published as of October 1 but imported (detected) on October 15.
        $detectedAfter = $this->event(MedicalFacilityEventType::Created, [
            'occurred_on' => '2026-10-01',
            'created_at' => '2026-10-15 05:30:00',
        ]);
        $this->event(MedicalFacilityEventType::Created, [
            'occurred_on' => '2026-10-01',
            'created_at' => '2026-10-09 05:30:00',
        ]);

        // 2026-10-10T00:00+09:00 is 2026-10-09T15:00Z, after the second event.
        $response = $this->getJson('/api/v1/medical-facility-events?'.http_build_query(['detected_since' => '2026-10-10T00:00:00+09:00']));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $detectedAfter->id);
        $response->assertJsonPath('data.0.detected_at', '2026-10-15T05:30:00.000000Z');
    }

    public function test_filters_by_the_facilitys_prefecture_and_institution_type(): void
    {
        $matching = $this->event(MedicalFacilityEventType::Created, [
            'medical_facility_id' => MedicalFacility::factory()->create(['prefecture_code' => '13', 'institution_type' => InstitutionType::Pharmacy]),
        ]);
        $this->event(MedicalFacilityEventType::Created, [
            'medical_facility_id' => MedicalFacility::factory()->create(['prefecture_code' => '13', 'institution_type' => InstitutionType::Clinic]),
        ]);
        $this->event(MedicalFacilityEventType::Created, [
            'medical_facility_id' => MedicalFacility::factory()->create(['prefecture_code' => '01', 'institution_type' => InstitutionType::Pharmacy]),
        ]);

        $response = $this->getJson('/api/v1/medical-facility-events?prefecture_code=13&institution_type='.InstitutionType::Pharmacy->value);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_per_page_limits_the_number_of_returned_events(): void
    {
        $this->event(MedicalFacilityEventType::Created);
        $this->event(MedicalFacilityEventType::Created);

        $response = $this->getJson('/api/v1/medical-facility-events?per_page=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 2);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidParameterProvider(): array
    {
        return [
            'unknown event_type' => [['event_type' => '9'], 'event_type'],
            'occurred_from not a date' => [['occurred_from' => '2026/10/01'], 'occurred_from'],
            'detected_since not a date' => [['detected_since' => 'yesterday-ish'], 'detected_since'],
            'occurred_to before occurred_from' => [['occurred_from' => '2026-10-01', 'occurred_to' => '2026-09-01'], 'occurred_to'],
            'invalid prefecture_code' => [['prefecture_code' => '48'], 'prefecture_code'],
            'unknown institution_type' => [['institution_type' => '9'], 'institution_type'],
            'per_page above maximum' => [['per_page' => '101'], 'per_page'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidParameterProvider')]
    public function test_returns_422_for_invalid_parameters(array $query, string $invalidField): void
    {
        $response = $this->getJson('/api/v1/medical-facility-events?'.http_build_query($query));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([$invalidField]);
    }

    public function test_page_numbers_reach_only_the_first_ten_thousand_rows(): void
    {
        config(['api.max_paginated_rows' => 10]);
        $this->event(MedicalFacilityEventType::Created);

        $this->getJson('/api/v1/medical-facility-events?per_page=5&page=2')
            ->assertOk()
            ->assertJsonPath('meta.max_page', 2);
        $this->getJson('/api/v1/medical-facility-events?per_page=5&page=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(MedicalFacilityEventType $type, array $attributes = []): MedicalFacilityEvent
    {
        return MedicalFacilityEvent::factory()->create([
            'event_type' => $type,
            'origin' => MedicalFacilityEventOrigin::Detected,
            'occurred_on' => '2026-10-01',
            'payload' => $type === MedicalFacilityEventType::Updated
                ? ['name' => ['old' => '旧名称', 'new' => '新名称']]
                : ['name' => '施設', 'address' => '住所'],
            ...$attributes,
        ]);
    }
}
