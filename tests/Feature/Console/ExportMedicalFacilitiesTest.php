<?php

namespace Tests\Feature\Console;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Models\MedicalFacility;
use App\Services\Export\FacilityExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportMedicalFacilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['rhb.scope.prefectures' => ['01', '13']]);
    }

    public function test_it_writes_csv_and_json_lines_per_prefecture_and_for_the_whole_scope(): void
    {
        MedicalFacility::factory()->count(2)->create(['prefecture_code' => '01']);
        MedicalFacility::factory()->create(['prefecture_code' => '13']);

        $this->artisan('rhb:export')->assertExitCode(0);

        $this->assertSame(
            [
                'medical-facilities-01.csv.gz', 'medical-facilities-01.jsonl.gz',
                'medical-facilities-13.csv.gz', 'medical-facilities-13.jsonl.gz',
                'medical-facilities-all.csv.gz', 'medical-facilities-all.jsonl.gz',
            ],
            array_column($this->manifest()['files'], 'name'),
        );
        $this->assertCount(2, $this->jsonLines('medical-facilities-01.jsonl.gz'));
        $this->assertCount(1, $this->jsonLines('medical-facilities-13.jsonl.gz'));
        $this->assertCount(3, $this->jsonLines('medical-facilities-all.jsonl.gz'));
        $this->assertCount(4, $this->csvRows('medical-facilities-all.csv.gz'), 'header + 3 rows');
    }

    public function test_json_lines_rows_match_the_api_resource_and_include_closed_facilities(): void
    {
        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13', 'status' => MedicalFacilityStatus::Closed]);

        $this->artisan('rhb:export')->assertExitCode(0);

        $row = $this->jsonLines('medical-facilities-13.jsonl.gz')[0];
        $api = $this->getJson("/api/v1/medical-facilities/{$facility->id}")->json('data');
        $this->assertSame($api, $row);
        $this->assertSame(['code' => 2, 'label' => '廃止'], $row['status']);
        $this->assertArrayNotHasKey('founder_name', $row);
        $this->assertArrayNotHasKey('administrator_name', $row);
    }

    public function test_csv_rows_flatten_codes_labels_and_nested_values(): void
    {
        MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'institution_type' => InstitutionType::Clinic,
            'facility_code' => '1012345',
            'name' => '診療所, "中央"',
            'designation_history' => [['reason' => '新規', 'date' => '2026-08-01']],
            'bed_counts' => ['一般' => 19],
            'department_categories' => [DepartmentBaseCategory::InternalMedicine, DepartmentBaseCategory::Ophthalmology],
        ]);

        $this->artisan('rhb:export')->assertExitCode(0);

        [$header, $values] = $this->csvRows('medical-facilities-13.csv.gz');
        $row = array_combine($header, $values);
        $this->assertSame(FacilityExporter::CSV_COLUMNS, $header);
        $this->assertSame('1311012345', $row['medical_institution_code']);
        $this->assertSame('2', $row['institution_type_code']);
        $this->assertSame('診療所', $row['institution_type']);
        $this->assertSame('診療所, "中央"', $row['name']);
        $this->assertSame('東京都', $row['prefecture']);
        // assertEquals: MySQL reorders JSON object keys on storage.
        $this->assertEquals([['reason' => '新規', 'date' => '2026-08-01']], json_decode($row['designation_history'], true));
        $this->assertSame(['一般' => 19], json_decode($row['bed_counts'], true));
        $this->assertSame('1|5', $row['department_category_codes']);
        $this->assertSame('内科|眼科', $row['department_categories']);
        $this->assertArrayNotHasKey('founder_name', $row);
    }

    public function test_only_facilities_within_the_scope_are_exported(): void
    {
        config(['rhb.scope.categories' => ['pharmacy']]);
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'institution_type' => InstitutionType::Pharmacy]);
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'institution_type' => InstitutionType::Hospital]);
        MedicalFacility::factory()->create(['prefecture_code' => '27', 'institution_type' => InstitutionType::Pharmacy]);

        $this->artisan('rhb:export')->assertExitCode(0);

        $rows = $this->jsonLines('medical-facilities-all.jsonl.gz');
        $this->assertCount(1, $rows);
        $this->assertSame(['code' => 4, 'label' => '薬局'], $rows[0]['institution_type']);
    }

    public function test_the_manifest_records_counts_sizes_and_checksums(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'updated_at' => '2026-10-01 05:30:00']);

        $this->artisan('rhb:export')->assertExitCode(0);

        $manifest = $this->manifest();
        $file = collect($manifest['files'])->firstWhere('name', 'medical-facilities-13.csv.gz');
        $path = Storage::disk('local')->path('exports/current/medical-facilities-13.csv.gz');
        $this->assertSame('2026-10-01T05:30:00.000000Z', $manifest['data_updated_at']);
        $this->assertSame(['code' => '13', 'label' => '東京都'], $file['prefecture']);
        $this->assertSame(1, $file['records']);
        $this->assertSame(filesize($path), $file['size']);
        $this->assertSame(hash_file('sha256', $path), $file['sha256']);
    }

    public function test_it_skips_when_nothing_changed_and_rebuilds_when_data_changed_or_forced(): void
    {
        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13', 'updated_at' => '2026-10-01 05:30:00']);
        $this->artisan('rhb:export')->assertExitCode(0);
        $firstGeneratedAt = $this->manifest()['generated_at'];

        $this->travel(1)->hours();
        $this->artisan('rhb:export')
            ->expectsOutputToContain('作り直しませんでした')
            ->assertExitCode(0);
        $this->assertSame($firstGeneratedAt, $this->manifest()['generated_at']);

        $this->artisan('rhb:export', ['--force' => true])->assertExitCode(0);
        $this->assertNotSame($firstGeneratedAt, $this->manifest()['generated_at']);

        $this->travel(1)->hours();
        $facility->update(['name' => '新しい名前']);
        $this->artisan('rhb:export')->assertExitCode(0);
        $this->assertSame('新しい名前', $this->jsonLines('medical-facilities-13.jsonl.gz')[0]['name']);
    }

    public function test_the_previous_set_is_kept_while_the_new_one_replaces_it(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13']);
        $this->artisan('rhb:export')->assertExitCode(0);

        $this->artisan('rhb:export', ['--force' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists('exports/previous/medical-facilities-13.csv.gz');
        Storage::disk('local')->assertExists('exports/current/medical-facilities-13.csv.gz');
        Storage::disk('local')->assertMissing('exports/building');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return json_decode((string) Storage::disk('local')->get('exports/current/manifest.json'), true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLines(string $filename): array
    {
        $content = gzdecode((string) Storage::disk('local')->get("exports/current/{$filename}"));

        return array_map(fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", $content))));
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $filename): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, gzdecode((string) Storage::disk('local')->get("exports/current/{$filename}")));
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }
}
