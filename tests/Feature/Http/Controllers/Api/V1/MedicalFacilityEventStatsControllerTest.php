<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventOrigin;
use App\Enums\MedicalFacilityEventType;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\Municipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicalFacilityEventStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_detected_closures_per_month_including_empty_months(): void
    {
        $this->closure('2026-01-01');
        $this->closure('2026-01-01');
        $this->closure('2026-03-01');
        $this->closure('2025-12-01');
        MedicalFacilityEvent::factory()->create(['event_type' => MedicalFacilityEventType::Created, 'occurred_on' => '2026-01-01']);
        // Neither the initial load nor a re-import is a real change.
        MedicalFacilityEvent::factory()->create([
            'event_type' => MedicalFacilityEventType::Removed,
            'origin' => MedicalFacilityEventOrigin::Reprocessed,
            'occurred_on' => '2026-01-01',
        ]);

        $response = $this->getJson('/api/v1/stats/facility-events?'.http_build_query([
            'group_by' => 'month',
            'event_type' => MedicalFacilityEventType::Removed->value,
            'occurred_from' => '2026-01-01',
            'occurred_to' => '2026-03-31',
        ]));

        $response->assertOk();
        $response->assertJsonPath('data', [
            ['key' => '2026-01', 'label' => '2026年1月', 'count' => 2],
            ['key' => '2026-02', 'label' => '2026年2月', 'count' => 0],
            ['key' => '2026-03', 'label' => '2026年3月', 'count' => 1],
        ]);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonStructure(['meta' => ['attribution' => ['notice', 'license', 'sources']]]);
    }

    public function test_counts_per_municipality_of_the_facility_and_applies_facility_filters(): void
    {
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);
        Municipality::factory()->create(['code' => '13102', 'prefecture_code' => '13', 'name' => '中央区']);
        $chuoClinic = MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'address' => '中央区銀座１丁目',
            'institution_type' => InstitutionType::Clinic,
            'department_categories' => [DepartmentBaseCategory::Ophthalmology],
        ]);
        $chiyodaClinic = MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'address' => '千代田区神田駿河台２丁目',
            'institution_type' => InstitutionType::Clinic,
            'department_categories' => [DepartmentBaseCategory::Ophthalmology],
        ]);
        $otherDepartment = MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'address' => '中央区銀座２丁目',
            'institution_type' => InstitutionType::Clinic,
            'department_categories' => [DepartmentBaseCategory::Surgery],
        ]);
        foreach ([$chuoClinic, $chuoClinic, $chiyodaClinic, $otherDepartment] as $facility) {
            MedicalFacilityEvent::factory()->for($facility)->create(['event_type' => MedicalFacilityEventType::Created]);
        }

        $response = $this->getJson('/api/v1/stats/facility-events?'.http_build_query([
            'group_by' => 'municipality',
            'event_type' => MedicalFacilityEventType::Created->value,
            'prefecture_code' => '13',
            'department_category' => DepartmentBaseCategory::Ophthalmology->value,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data', [
            ['key' => '13102', 'label' => '中央区', 'count' => 2],
            ['key' => '13101', 'label' => '千代田区', 'count' => 1],
        ]);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_results_are_marked_cacheable_for_an_hour(): void
    {
        $this->getJson('/api/v1/stats/facility-events?group_by=municipality&event_type=1')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_shares_the_per_ip_limit_on_uncached_computations(): void
    {
        config(['api.stats_computations_per_minute' => 1]);

        $this->getJson('/api/v1/stats/facilities?group_by=municipality')->assertOk();

        $this->getJson('/api/v1/stats/facility-events?group_by=municipality&event_type=1')->assertTooManyRequests();
    }

    /**
     * @return array<string, array{array<string, string|int>, string, string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'group_by missing' => [['event_type' => 1], 'group_by', 'The group by field is required.'],
            'group_by by department' => [['group_by' => 'department_category', 'event_type' => 1], 'group_by', 'The selected group by is invalid.'],
            'event_type missing' => [['group_by' => 'municipality'], 'event_type', 'The event type field is required.'],
            'event_type changed' => [['group_by' => 'municipality', 'event_type' => 3], 'event_type', 'The selected event type is invalid.'],
            'month without a start' => [['group_by' => 'month', 'event_type' => 2, 'occurred_to' => '2026-01-31'], 'occurred_from', 'The occurred from field is required.'],
            'month over 60 months' => [['group_by' => 'month', 'event_type' => 2, 'occurred_from' => '2021-01-01', 'occurred_to' => '2026-01-01'], 'occurred_to', 'The period must not exceed 60 months.'],
        ];
    }

    /**
     * @param  array<string, string|int>  $query
     */
    #[DataProvider('invalidRequests')]
    public function test_rejects_invalid_requests(array $query, string $field, string $message): void
    {
        $this->getJson('/api/v1/stats/facility-events?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    }

    private function closure(string $occurredOn): MedicalFacilityEvent
    {
        return MedicalFacilityEvent::factory()->create([
            'event_type' => MedicalFacilityEventType::Removed,
            'occurred_on' => $occurredOn,
        ]);
    }
}
