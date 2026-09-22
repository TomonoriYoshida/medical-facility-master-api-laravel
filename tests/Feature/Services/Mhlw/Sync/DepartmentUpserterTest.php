<?php

namespace Tests\Feature\Services\Mhlw\Sync;

use App\Enums\MedicalFacilityEventType;
use App\Models\MedicalFacility;
use App\Models\MhlwDatasetDownload;
use App\Services\Mhlw\Sync\DepartmentUpserter;
use App\Services\Mhlw\Sync\DepartmentUpsertOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentUpserterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_department_code_creates_the_department_and_a_created_event(): void
    {
        $facility = MedicalFacility::factory()->create();
        $download = MhlwDatasetDownload::factory()->create(['published_on' => '2026-06-01']);
        $mapped = $this->mappedAttributes(['department_code' => '01001', 'department_name' => '内科']);

        $result = (new DepartmentUpserter)->upsert($mapped, $facility, $download);

        $this->assertSame(DepartmentUpsertOutcome::Created, $result['outcome']);
        $this->assertSame($facility->id, $result['department']->medical_facility_id);
        $this->assertSame('01001', $result['department']->department_code);
        $this->assertSame($download->id, $result['department']->last_seen_mhlw_dataset_download_id);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $facility->id,
            'department_code' => '01001',
            'event_type' => MedicalFacilityEventType::Created,
            'occurred_on' => '2026-06-01',
        ]);
    }

    public function test_the_source_id_key_does_not_leak_into_the_department_attributes(): void
    {
        $facility = MedicalFacility::factory()->create();
        $download = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['source_id' => '0000000000099', 'department_code' => '01001']);

        $result = (new DepartmentUpserter)->upsert($mapped, $facility, $download);

        $this->assertArrayNotHasKey('source_id', $result['department']->getAttributes());
    }

    public function test_an_unchanged_department_only_touches_the_watermark_and_records_no_event(): void
    {
        $facility = MedicalFacility::factory()->create();
        $download1 = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['department_code' => '01001']);
        $first = (new DepartmentUpserter)->upsert($mapped, $facility, $download1);

        $download2 = MhlwDatasetDownload::factory()->create();
        $result = (new DepartmentUpserter)->upsert($mapped, $facility, $download2);

        $this->assertSame(DepartmentUpsertOutcome::Unchanged, $result['outcome']);
        $this->assertSame($download2->id, $result['department']->fresh()->last_seen_mhlw_dataset_download_id);
        $this->assertSame(1, $first['department']->fresh()->medicalFacility->events()->count());
    }

    public function test_a_changed_department_updates_and_records_an_updated_event(): void
    {
        $facility = MedicalFacility::factory()->create();
        $download1 = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['department_code' => '01001', 'department_name' => '内科']);
        (new DepartmentUpserter)->upsert($mapped, $facility, $download1);

        $download2 = MhlwDatasetDownload::factory()->create(['published_on' => '2026-12-01']);
        $updated = $this->mappedAttributes(['department_code' => '01001', 'department_name' => '内科・小児科']);
        $result = (new DepartmentUpserter)->upsert($updated, $facility, $download2);

        $this->assertSame(DepartmentUpsertOutcome::Updated, $result['outcome']);
        $this->assertSame('内科・小児科', $result['department']->fresh()->department_name);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $facility->id,
            'department_code' => '01001',
            'event_type' => MedicalFacilityEventType::Updated,
            'occurred_on' => '2026-12-01',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mappedAttributes(array $overrides = []): array
    {
        $emptyWeek = ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [], 'holiday' => []];

        return array_merge([
            'source_id' => '0000000000000',
            'department_code' => '01001',
            'department_name' => '内科',
            'consultation_hours' => $emptyWeek,
            'reception_hours' => $emptyWeek,
        ], $overrides);
    }
}
