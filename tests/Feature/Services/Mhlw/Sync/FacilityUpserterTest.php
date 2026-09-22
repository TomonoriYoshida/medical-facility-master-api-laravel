<?php

namespace Tests\Feature\Services\Mhlw\Sync;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MhlwDatasetDownload;
use App\Services\Mhlw\Sync\FacilityUpserter;
use App\Services\Mhlw\Sync\FacilityUpsertOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityUpserterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_source_id_creates_the_facility_and_a_created_event(): void
    {
        $download = MhlwDatasetDownload::factory()->create(['published_on' => '2026-06-01']);
        $mapped = $this->mappedAttributes(['source_id' => '0001', 'name' => '新規病院']);

        $result = (new FacilityUpserter)->upsert($mapped, $download);

        $this->assertSame(FacilityUpsertOutcome::Created, $result['outcome']);
        $this->assertSame('0001', $result['facility']->source_id);
        $this->assertSame(MedicalFacilityStatus::Active, $result['facility']->status);
        $this->assertSame($download->id, $result['facility']->last_seen_mhlw_dataset_download_id);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $result['facility']->id,
            'department_code' => null,
            'event_type' => MedicalFacilityEventType::Created,
            'occurred_on' => '2026-06-01',
            'mhlw_dataset_download_id' => $download->id,
        ]);
    }

    public function test_an_unchanged_active_facility_only_touches_the_watermark_and_records_no_event(): void
    {
        $download1 = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['source_id' => '0002']);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = MhlwDatasetDownload::factory()->create();
        $result = (new FacilityUpserter)->upsert($mapped, $download2);

        $this->assertSame(FacilityUpsertOutcome::Unchanged, $result['outcome']);
        $this->assertSame($download2->id, $result['facility']->fresh()->last_seen_mhlw_dataset_download_id);
        $this->assertSame(1, $result['facility']->events()->count());
    }

    public function test_a_changed_active_facility_updates_and_records_an_updated_event_with_only_the_changed_fields(): void
    {
        $download1 = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['source_id' => '0003', 'name' => '旧名称']);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = MhlwDatasetDownload::factory()->create(['published_on' => '2026-12-01']);
        $updated = $this->mappedAttributes(['source_id' => '0003', 'name' => '新名称']);
        $result = (new FacilityUpserter)->upsert($updated, $download2);

        $this->assertSame(FacilityUpsertOutcome::Updated, $result['outcome']);
        $this->assertSame('新名称', $result['facility']->fresh()->name);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $result['facility']->id,
            'event_type' => MedicalFacilityEventType::Updated,
            'occurred_on' => '2026-12-01',
        ]);

        $event = $result['facility']->events()->where('event_type', MedicalFacilityEventType::Updated)->sole();
        // assertEquals, not assertSame: MySQL's JSON column round-trip
        // does not guarantee preserving object member order, and the
        // payload's key order was never a semantic requirement here.
        $this->assertEquals(['name' => ['old' => '旧名称', 'new' => '新名称']], $event->payload);
    }

    public function test_a_closed_facility_that_reappears_is_reopened_with_a_created_event_not_updated(): void
    {
        $download1 = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['source_id' => '0004']);
        $first = (new FacilityUpserter)->upsert($mapped, $download1);
        $first['facility']->update(['status' => MedicalFacilityStatus::Closed]);

        $download2 = MhlwDatasetDownload::factory()->create();
        $result = (new FacilityUpserter)->upsert($mapped, $download2);

        $this->assertSame(FacilityUpsertOutcome::Reopened, $result['outcome']);
        $this->assertSame(MedicalFacilityStatus::Active, $result['facility']->fresh()->status);

        $eventTypes = $result['facility']->events()->pluck('event_type')->all();
        $this->assertSame([MedicalFacilityEventType::Created, MedicalFacilityEventType::Created], $eventTypes);
    }

    public function test_latitude_and_longitude_alone_do_not_trigger_a_false_updated_event(): void
    {
        // Regression test at the Feature level (real MySQL decimal:6
        // round-trip), mirroring AttributeDiffTest's unit-level coverage
        // of the same bug.
        $download1 = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['source_id' => '0005', 'latitude' => 35.658581, 'longitude' => 139.700592]);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = MhlwDatasetDownload::factory()->create();
        $result = (new FacilityUpserter)->upsert($mapped, $download2);

        $this->assertSame(FacilityUpsertOutcome::Unchanged, $result['outcome']);
    }

    public function test_closure_schedule_json_round_trip_does_not_trigger_a_false_updated_event(): void
    {
        $download1 = MhlwDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['source_id' => '0006']);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = MhlwDatasetDownload::factory()->create();
        $result = (new FacilityUpserter)->upsert($mapped, $download2);

        $this->assertSame(FacilityUpsertOutcome::Unchanged, $result['outcome']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mappedAttributes(array $overrides = []): array
    {
        return array_merge([
            'source_id' => '0000000000000',
            'institution_type' => InstitutionType::Hospital,
            'name' => 'テスト病院',
            'name_kana' => 'テストビョウイン',
            'short_name' => null,
            'short_name_kana' => null,
            'name_en' => null,
            'prefecture_code' => '01',
            'city_code' => '101',
            'address' => '北海道札幌市中央区',
            'latitude' => 43.055405,
            'longitude' => 141.333497,
            'website_url' => null,
            'closure_schedule' => [
                'weekly' => ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 0, 'sun' => 0],
                'monthly_pattern' => [],
                'holiday' => 0,
                'other_closed_dates' => [],
            ],
            'business_hours' => null,
            'reception_hours' => null,
            'general_beds' => 100,
            'sanatorium_beds' => null,
            'sanatorium_beds_medical_insurance' => null,
            'sanatorium_beds_care_insurance' => null,
            'psychiatric_beds' => null,
            'tuberculosis_beds' => null,
            'infectious_disease_beds' => null,
            'total_beds' => 100,
        ], $overrides);
    }
}
