<?php

namespace Tests\Feature\Console;

use App\Enums\InstitutionType;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Jobs\ImportRhbFacilityListJob;
use App\Models\RhbDatasetDownload;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class ImportRhbDatasetsTest extends TestCase
{
    use BuildsXlsxFixture;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->cleanUpXlsxFixtures();

        parent::tearDown();
    }

    public function test_it_batches_one_job_per_category_for_the_configured_bureau(): void
    {
        Bus::fake();

        $this->artisan('rhb:import')->assertExitCode(0);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            $categories = $batch->jobs->map(fn (ImportRhbFacilityListJob $job) => $job->category);

            return $batch->jobs->count() === 3
                && $batch->jobs->every(fn ($job) => $job instanceof ImportRhbFacilityListJob && $job->bureau === RhbBureau::Hokkaido)
                && $categories->contains(RhbCategory::Medical)
                && $categories->contains(RhbCategory::Dental)
                && $categories->contains(RhbCategory::Pharmacy);
        });
    }

    public function test_the_bureau_option_limits_the_batch(): void
    {
        Bus::fake();

        $this->artisan('rhb:import', ['--bureau' => ['hokkaido']])->assertExitCode(0);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3);
    }

    public function test_an_unknown_bureau_key_is_rejected(): void
    {
        $this->artisan('rhb:import', ['--bureau' => ['unknown']])->assertExitCode(1);
    }

    public function test_it_imports_end_to_end_with_the_wait_flag(): void
    {
        Storage::fake('local');

        $path = $this->createXlsx([
            ['header'],
            [
                '1', '01,1248,9', '医療法人　愛全病院',
                '〒005－0813札幌市南区川沿１３条２丁目１番３８号', '011-571-5670',
                '医療法人　愛全会', '松原　泉', '昭47. 3. 1', "療養\u{3000}\u{3000} 206", '病院',
            ],
        ]);
        $localPath = 'rhb/hokkaido/medical/hospital.xlsx';
        Storage::disk('local')->put($localPath, file_get_contents($path));

        RhbDatasetDownload::factory()->create([
            'bureau_code' => RhbBureau::Hokkaido,
            'category' => RhbCategory::Medical,
            'prefecture_codes' => ['01'],
            'filename' => 'hospital.xlsx',
            'local_path' => $localPath,
        ]);

        $this->artisan('rhb:import', ['--wait' => true])->assertExitCode(0);

        $this->assertDatabaseHas('medical_facilities', [
            'facility_code' => '0112489',
            'institution_type' => InstitutionType::Hospital,
        ]);
    }
}
