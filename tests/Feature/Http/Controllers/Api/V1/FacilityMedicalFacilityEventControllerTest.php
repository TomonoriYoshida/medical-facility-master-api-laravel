<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\MedicalFacilityEventOrigin;
use App\Enums\MedicalFacilityEventType;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityMedicalFacilityEventControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_facilitys_history_newest_first_including_when_tracking_started(): void
    {
        $facility = MedicalFacility::factory()->create();
        $baseline = $this->event($facility, MedicalFacilityEventType::Created, MedicalFacilityEventOrigin::Baseline, '2026-09-01');
        $removed = $this->event($facility, MedicalFacilityEventType::Removed, MedicalFacilityEventOrigin::Detected, '2026-10-01');
        $reopened = $this->event($facility, MedicalFacilityEventType::Created, MedicalFacilityEventOrigin::Detected, '2026-11-01');

        $response = $this->getJson("/api/v1/medical-facilities/{$facility->id}/events");

        $response->assertOk();
        $this->assertSame([$reopened->id, $removed->id, $baseline->id], $response->json('data.*.id'));
        $response->assertJsonPath('data.0.is_reopening', true);
        $response->assertJsonPath('data.2.origin', ['code' => 1, 'label' => '初回取込']);
        $response->assertJsonPath('data.2.is_reopening', false);
        $response->assertJsonMissingPath('data.0.facility');
        $response->assertJsonPath('meta.attribution.license.name', '公共データ利用規約（第1.0版）');
    }

    public function test_excludes_reprocessing_and_other_facilities_events(): void
    {
        $facility = MedicalFacility::factory()->create();
        $detected = $this->event($facility, MedicalFacilityEventType::Updated, MedicalFacilityEventOrigin::Detected, '2026-10-01');
        $this->event($facility, MedicalFacilityEventType::Updated, MedicalFacilityEventOrigin::Reprocessed, '2026-10-01');
        $this->event(MedicalFacility::factory()->create(), MedicalFacilityEventType::Updated, MedicalFacilityEventOrigin::Detected, '2026-10-01');

        $response = $this->getJson("/api/v1/medical-facilities/{$facility->id}/events");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $detected->id);
    }

    public function test_returns_404_for_an_unknown_facility(): void
    {
        $this->getJson('/api/v1/medical-facilities/999999/events')->assertNotFound();
    }

    private function event(
        MedicalFacility $facility,
        MedicalFacilityEventType $type,
        MedicalFacilityEventOrigin $origin,
        string $occurredOn,
    ): MedicalFacilityEvent {
        return MedicalFacilityEvent::factory()->create([
            'medical_facility_id' => $facility->id,
            'event_type' => $type,
            'origin' => $origin,
            'occurred_on' => $occurredOn,
            'payload' => $type === MedicalFacilityEventType::Updated
                ? ['name' => ['old' => '旧名称', 'new' => '新名称']]
                : ['name' => '施設', 'address' => '住所'],
        ]);
    }
}
