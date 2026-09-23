<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowMedicalFacilityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_full_facility_with_japanese_enum_labels(): void
    {
        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'status' => MedicalFacilityStatus::Active,
            'bureau_code' => RhbBureau::Hokkaido,
            'department_categories' => [DepartmentBaseCategory::Cardiology, DepartmentBaseCategory::Dermatology],
        ]);

        $response = $this->getJson("/api/v1/medical-facilities/{$facility->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $facility->id);
        $response->assertJsonPath('data.facility_code', $facility->facility_code);
        $response->assertJsonPath('data.name', $facility->name);
        $response->assertJsonPath('data.institution_type', '診療所');
        $response->assertJsonPath('data.status', '指定中');
        $response->assertJsonPath('data.bureau_code', '北海道厚生局');
        $response->assertJsonPath('data.department_categories', ['循環器内科', '皮膚科']);
    }

    public function test_includes_attribution_meta(): void
    {
        $facility = MedicalFacility::factory()->create();

        $response = $this->getJson("/api/v1/medical-facilities/{$facility->id}");

        $response->assertOk();
        $response->assertJsonCount(8, 'meta.attribution.sources');
    }

    public function test_returns_404_when_facility_does_not_exist(): void
    {
        $response = $this->getJson('/api/v1/medical-facilities/999999');

        $response->assertNotFound();
    }
}
