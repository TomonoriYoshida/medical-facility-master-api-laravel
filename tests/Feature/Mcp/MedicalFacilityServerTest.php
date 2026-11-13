<?php

namespace Tests\Feature\Mcp;

use App\Enums\DepartmentBaseCategory;
use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Mcp\Servers\MedicalFacilityServer;
use App\Mcp\Tools\FindStationsTool;
use App\Mcp\Tools\GetFacilityTool;
use App\Mcp\Tools\SearchFacilitiesTool;
use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use App\Models\MedicalInfoNetSchedule;
use App\Models\RailwayStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalFacilityServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_stations_returns_the_stations_of_a_name(): void
    {
        RailwayStation::factory()->create(['name' => '春日', 'name_normalized' => '春日', 'latitude' => 35.709222, 'longitude' => 139.752703]);

        MedicalFacilityServer::tool(FindStationsTool::class, ['name' => '春日駅'])
            ->assertOk()
            ->assertName('find_stations')
            ->assertStructuredContentPath('stations.0.name', '春日')
            ->assertStructuredContentPath('stations.0.latitude', 35.709222)
            ->assertStructuredContentPath('attribution.sources', ['「国土数値情報（鉄道データ）」（国土交通省）を加工して作成'])
            ->assertStructuredContentPath('attribution.details', route('scramble.docs.ui'));
    }

    public function test_find_stations_reports_a_missing_name(): void
    {
        MedicalFacilityServer::tool(FindStationsTool::class, [])
            ->assertHasErrors();
    }

    public function test_search_facilities_returns_active_facilities_nearest_first_in_short(): void
    {
        $far = $this->facility('遠いクリニック', 35.7150, 139.7527);
        $near = $this->facility('近いクリニック', 35.7095, 139.7527, [DepartmentBaseCategory::Dermatology]);
        $closed = $this->facility('廃止クリニック', 35.7093, 139.7527);
        $closed->forceFill(['status' => MedicalFacilityStatus::Closed])->save();

        $response = MedicalFacilityServer::tool(SearchFacilitiesTool::class, [
            'latitude' => 35.709222,
            'longitude' => 139.752703,
            'radius' => 1000,
        ]);

        $response->assertOk()
            ->assertName('search_facilities')
            ->assertStructuredContentPath('total', 2)
            ->assertStructuredContentPath('total_is_capped', false)
            ->assertStructuredContentPath('facilities.0.id', $near->id)
            ->assertStructuredContentPath('facilities.0.name', '近いクリニック')
            ->assertStructuredContentPath('facilities.0.type', '診療所')
            ->assertStructuredContentPath('facilities.0.departments', ['皮膚科'])
            ->assertStructuredContentPath('facilities.0.distance', 31)
            ->assertStructuredContentPath('facilities.0.address', '東京都文京区春日1丁目')
            ->assertStructuredContentPath('facilities.1.id', $far->id)
            ->assertStructuredContentMissingPath('facilities.0.bureau')
            ->assertStructuredContentPath('attribution.notice', '本APIのデータは、各地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を加工して作成しています。');
    }

    public function test_an_address_already_starting_with_its_prefecture_is_kept_as_it_is(): void
    {
        $facility = $this->facility('福岡クリニック', 33.5900, 130.4000);
        $facility->forceFill(['prefecture_code' => '40', 'address' => '福岡県福岡市中央区天神1丁目'])->save();

        MedicalFacilityServer::tool(SearchFacilitiesTool::class, ['prefecture_code' => '40'])
            ->assertOk()
            ->assertStructuredContentPath('facilities.0.address', '福岡県福岡市中央区天神1丁目');
    }

    public function test_search_facilities_filters_by_department(): void
    {
        $this->facility('内科クリニック', 35.7095, 139.7527, [DepartmentBaseCategory::InternalMedicine]);
        $dermatology = $this->facility('皮膚科クリニック', 35.7096, 139.7527, [DepartmentBaseCategory::Dermatology]);

        MedicalFacilityServer::tool(SearchFacilitiesTool::class, ['department_category' => DepartmentBaseCategory::Dermatology->value])
            ->assertOk()
            ->assertStructuredContentPath('total', 1)
            ->assertStructuredContentPath('facilities.0.id', $dermatology->id);
    }

    public function test_search_facilities_reports_invalid_arguments(): void
    {
        MedicalFacilityServer::tool(SearchFacilitiesTool::class, ['per_page' => 21])
            ->assertHasErrors(['per_page は20以下にしてください。']);
        MedicalFacilityServer::tool(SearchFacilitiesTool::class, ['radius' => 10001])
            ->assertHasErrors(['radius は10000メートル以下にしてください。']);
        // The API's own validation, passed on.
        MedicalFacilityServer::tool(SearchFacilitiesTool::class, ['open_at' => 'いま'])
            ->assertHasErrors(['open_at は正しい日付にしてください。']);
    }

    public function test_get_facility_returns_the_facility_with_its_opening_hours(): void
    {
        $location = MedicalInfoNetLocation::factory()->create();
        $schedule = MedicalInfoNetSchedule::factory()->create(['source_id' => $location->source_id]);
        $facility = $this->facility('春日クリニック', 35.7095, 139.7527);
        $facility->forceFill(['medical_info_net_id' => $location->source_id])->save();

        MedicalFacilityServer::tool(GetFacilityTool::class, ['id' => $facility->id])
            ->assertOk()
            ->assertName('get_facility')
            ->assertStructuredContentPath('facility.name', '春日クリニック')
            ->assertStructuredContentPath('facility.status', '指定中')
            ->assertStructuredContentPath('opening_hours.schedules', $schedule->schedules);
    }

    public function test_get_facility_without_a_match_has_no_opening_hours(): void
    {
        $facility = $this->facility('春日クリニック', 35.7095, 139.7527);

        MedicalFacilityServer::tool(GetFacilityTool::class, ['id' => $facility->id])
            ->assertOk()
            ->assertStructuredContentPath('opening_hours', null);
    }

    public function test_get_facility_reports_an_unknown_facility(): void
    {
        MedicalFacilityServer::tool(GetFacilityTool::class, ['id' => 999999])
            ->assertHasErrors(['見つかりません。']);
    }

    public function test_the_server_is_served_over_http_and_rate_limited(): void
    {
        config(['api.mcp_rate_limit_per_minute' => 2]);
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25'];
        $listTools = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

        $response = $this->postJson('/mcp', $listTools, $headers);

        $response->assertOk();
        $this->assertSame(['find_stations', 'search_facilities', 'get_facility'], $response->json('result.tools.*.name'));
        $this->postJson('/mcp', $listTools, $headers)->assertOk();
        $this->postJson('/mcp', $listTools, $headers)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'リクエストが多すぎます。しばらく待ってから、もう一度お試しください。');
    }

    /**
     * An active, located clinic.
     *
     * @param  list<DepartmentBaseCategory>  $departments
     */
    private function facility(string $name, float $latitude, float $longitude, array $departments = [DepartmentBaseCategory::InternalMedicine]): MedicalFacility
    {
        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'name' => $name,
            'prefecture_code' => '13',
            'address' => '文京区春日1丁目',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'department_categories' => $departments,
        ]);
        $facility->forceFill(['geocode_level' => GeocodeLevel::Residence])->save();

        return $facility;
    }
}
