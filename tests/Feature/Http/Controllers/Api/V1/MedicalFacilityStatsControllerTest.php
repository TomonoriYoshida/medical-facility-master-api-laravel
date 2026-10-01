<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Models\MedicalFacility;
use App\Models\Municipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicalFacilityStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_new_openings_per_month_including_empty_months(): void
    {
        $this->newOpening('2026-01-05');
        $this->newOpening('2026-01-20');
        $this->newOpening('2026-03-31');
        $this->newOpening('2025-12-31');
        MedicalFacility::factory()->create([
            'designated_on' => '2026-01-10',
            'designation_history' => [['reason' => '交代', 'date' => '2026-01-10']],
        ]);

        $response = $this->getJson('/api/v1/stats/facilities?'.http_build_query([
            'group_by' => 'month',
            'designation_reason' => '新規',
            'designated_from' => '2026-01-01',
            'designated_to' => '2026-03-31',
        ]));

        $response->assertOk();
        $response->assertJsonPath('data', [
            ['key' => '2026-01', 'label' => '2026年1月', 'count' => 2],
            ['key' => '2026-02', 'label' => '2026年2月', 'count' => 0],
            ['key' => '2026-03', 'label' => '2026年3月', 'count' => 1],
        ]);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.group_by', 'month');
        $response->assertJsonStructure(['meta' => ['attribution' => ['notice', 'license', 'sources']]]);
    }

    public function test_counts_per_municipality_most_first_with_unresolved_addresses_as_null(): void
    {
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);
        Municipality::factory()->create(['code' => '13102', 'prefecture_code' => '13', 'name' => '中央区']);
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区神田駿河台２丁目']);
        MedicalFacility::factory()->count(2)->create(['prefecture_code' => '13', 'address' => '中央区銀座１丁目']);
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '存在しない市']);
        MedicalFacility::factory()->create(['prefecture_code' => '01']);

        // prefecture_code exists on both joined tables, so this also guards the filter's qualification.
        $response = $this->getJson('/api/v1/stats/facilities?group_by=municipality&prefecture_code=13');

        $response->assertOk();
        $response->assertJsonPath('data', [
            ['key' => '13102', 'label' => '中央区', 'count' => 2],
            ['key' => null, 'label' => null, 'count' => 1],
            ['key' => '13101', 'label' => '千代田区', 'count' => 1],
        ]);
        $response->assertJsonPath('meta.total', 4);
    }

    public function test_counts_each_department_of_a_facility_so_counts_can_exceed_the_total(): void
    {
        MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'department_categories' => [DepartmentBaseCategory::InternalMedicine, DepartmentBaseCategory::Pediatrics],
        ]);
        MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'department_categories' => [DepartmentBaseCategory::InternalMedicine],
        ]);
        MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Hospital,
            'department_categories' => [DepartmentBaseCategory::Surgery],
        ]);

        $response = $this->getJson('/api/v1/stats/facilities?group_by=department_category&institution_type=2');

        $response->assertOk();
        $response->assertJsonPath('data', [
            ['key' => DepartmentBaseCategory::InternalMedicine->value, 'label' => '内科', 'count' => 2],
            ['key' => DepartmentBaseCategory::Pediatrics->value, 'label' => '小児科', 'count' => 1],
        ]);
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_results_are_cached_and_marked_cacheable_for_an_hour(): void
    {
        MedicalFacility::factory()->create();

        $first = $this->getJson('/api/v1/stats/facilities?group_by=municipality');
        MedicalFacility::factory()->create();
        $second = $this->getJson('/api/v1/stats/facilities?group_by=municipality');

        $first->assertHeader('Cache-Control', 'max-age=3600, public');
        $second->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/stats/facilities?group_by=municipality&prefecture_code=01')->assertJsonPath('meta.total', 2);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'group_by missing' => [[], 'group_by', 'The group by field is required.'],
            'group_by unknown' => [['group_by' => 'bureau'], 'group_by', 'The selected group by is invalid.'],
            'month without a start' => [['group_by' => 'month', 'designated_to' => '2026-01-31'], 'designated_from', 'The designated from field is required.'],
            'month without an end' => [['group_by' => 'month', 'designated_from' => '2026-01-01'], 'designated_to', 'The designated to field is required.'],
            'month over 60 months' => [['group_by' => 'month', 'designated_from' => '2021-01-01', 'designated_to' => '2026-01-01'], 'designated_to', 'The period must not exceed 60 months.'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidRequests')]
    public function test_rejects_invalid_requests(array $query, string $field, string $message): void
    {
        $this->getJson('/api/v1/stats/facilities?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    }

    public function test_accepts_exactly_60_months(): void
    {
        $response = $this->getJson('/api/v1/stats/facilities?group_by=month&designated_from=2021-02-01&designated_to=2026-01-31');

        $response->assertOk();
        $response->assertJsonCount(60, 'data');
    }

    private function newOpening(string $designatedOn): MedicalFacility
    {
        return MedicalFacility::factory()->create([
            'designated_on' => $designatedOn,
            'designation_history' => [['reason' => '新規', 'date' => $designatedOn]],
        ]);
    }
}
