<?php

namespace Tests\Feature\Services\Rhb\Sync;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Sync\FacilityUpserter;
use App\Services\Rhb\Sync\FacilityUpsertOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityUpserterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_facility_code_creates_the_facility_and_a_created_event(): void
    {
        $download = RhbDatasetDownload::factory()->create(['published_on' => '2026-06-01']);
        $mapped = $this->mappedAttributes(['facility_code' => '0000001', 'name' => '新規病院']);

        $result = (new FacilityUpserter)->upsert($mapped, $download);

        $this->assertSame(FacilityUpsertOutcome::Created, $result['outcome']);
        $this->assertSame('0000001', $result['facility']->facility_code);
        $this->assertSame(MedicalFacilityStatus::Active, $result['facility']->status);
        $this->assertSame($download->id, $result['facility']->last_seen_rhb_dataset_download_id);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $result['facility']->id,
            'event_type' => MedicalFacilityEventType::Created,
            'occurred_on' => '2026-06-01',
            'rhb_dataset_download_id' => $download->id,
        ]);
    }

    public function test_a_new_facility_can_be_created_directly_as_suspended(): void
    {
        $download = RhbDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['facility_code' => '0000002', 'status' => MedicalFacilityStatus::Suspended]);

        $result = (new FacilityUpserter)->upsert($mapped, $download);

        $this->assertSame(MedicalFacilityStatus::Suspended, $result['facility']->status);
        $this->assertSame(FacilityUpsertOutcome::Created, $result['outcome']);
    }

    public function test_an_unchanged_active_facility_only_touches_the_watermark_and_records_no_event(): void
    {
        $download1 = RhbDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['facility_code' => '0000003']);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = RhbDatasetDownload::factory()->create();
        $result = (new FacilityUpserter)->upsert($mapped, $download2);

        $this->assertSame(FacilityUpsertOutcome::Unchanged, $result['outcome']);
        $this->assertSame($download2->id, $result['facility']->fresh()->last_seen_rhb_dataset_download_id);
        $this->assertSame(1, $result['facility']->events()->count());
    }

    public function test_a_changed_active_facility_updates_and_records_an_updated_event_with_only_the_changed_fields(): void
    {
        $download1 = RhbDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['facility_code' => '0000004', 'name' => '旧名称']);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = RhbDatasetDownload::factory()->create(['published_on' => '2026-12-01']);
        $updated = $this->mappedAttributes(['facility_code' => '0000004', 'name' => '新名称']);
        $result = (new FacilityUpserter)->upsert($updated, $download2);

        $this->assertSame(FacilityUpsertOutcome::Updated, $result['outcome']);
        $this->assertSame('新名称', $result['facility']->fresh()->name);

        $this->assertDatabaseHas('medical_facility_events', [
            'medical_facility_id' => $result['facility']->id,
            'event_type' => MedicalFacilityEventType::Updated,
            'occurred_on' => '2026-12-01',
        ]);

        $event = $result['facility']->events()->where('event_type', MedicalFacilityEventType::Updated)->sole();
        $this->assertEquals(['name' => ['old' => '旧名称', 'new' => '新名称']], $event->payload);
    }

    public function test_a_closed_facility_that_reappears_is_reopened_with_a_created_event_not_updated(): void
    {
        $download1 = RhbDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['facility_code' => '0000005']);
        $first = (new FacilityUpserter)->upsert($mapped, $download1);
        $first['facility']->update(['status' => MedicalFacilityStatus::Closed]);

        $download2 = RhbDatasetDownload::factory()->create();
        $result = (new FacilityUpserter)->upsert($mapped, $download2);

        $this->assertSame(FacilityUpsertOutcome::Reopened, $result['outcome']);
        $this->assertSame(MedicalFacilityStatus::Active, $result['facility']->fresh()->status);

        $eventTypes = $result['facility']->events()->pluck('event_type')->all();
        $this->assertSame([MedicalFacilityEventType::Created, MedicalFacilityEventType::Created], $eventTypes);
    }

    public function test_a_suspended_active_transition_is_recorded_as_a_plain_updated_event(): void
    {
        $download1 = RhbDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['facility_code' => '0000006', 'status' => MedicalFacilityStatus::Active]);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = RhbDatasetDownload::factory()->create();
        $suspended = $this->mappedAttributes(['facility_code' => '0000006', 'status' => MedicalFacilityStatus::Suspended]);
        $result = (new FacilityUpserter)->upsert($suspended, $download2);

        $this->assertSame(FacilityUpsertOutcome::Updated, $result['outcome']);
        $this->assertSame(MedicalFacilityStatus::Suspended, $result['facility']->fresh()->status);
    }

    public function test_department_categories_alone_do_not_trigger_a_false_updated_event(): void
    {
        // Regression test at the Feature level (real AsEnumCollection cast
        // round-trip), mirroring AttributeDiffTest's unit-level coverage
        // of the same bug.
        $download1 = RhbDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes([
            'facility_code' => '0000007',
            'department_categories' => [DepartmentBaseCategory::InternalMedicine, DepartmentBaseCategory::Surgery],
        ]);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = RhbDatasetDownload::factory()->create();
        $result = (new FacilityUpserter)->upsert($mapped, $download2);

        $this->assertSame(FacilityUpsertOutcome::Unchanged, $result['outcome']);
    }

    public function test_designated_on_alone_does_not_trigger_a_false_updated_event(): void
    {
        // Regression test at the Feature level (real `date`-cast Carbon
        // round-trip), mirroring AttributeDiffTest's unit-level coverage.
        $download1 = RhbDatasetDownload::factory()->create();
        $mapped = $this->mappedAttributes(['facility_code' => '0000008', 'designated_on' => '2010-04-01']);
        (new FacilityUpserter)->upsert($mapped, $download1);

        $download2 = RhbDatasetDownload::factory()->create();
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
            'facility_code' => '0000000',
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
        ], $overrides);
    }
}
