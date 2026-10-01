<?php

namespace Tests\Feature\Console;

use App\Models\MedicalFacility;
use App\Models\Municipality;
use App\Services\Address\MunicipalityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AssignMunicipalitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_facility_gets_its_municipality_from_the_address(): void
    {
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);

        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区神田駿河台２丁目']);

        $this->assertSame('13101', $facility->municipality_code);
    }

    public function test_it_assigns_municipalities_to_facilities_created_before_they_were_seeded(): void
    {
        $facility = MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'address' => '千代田区神田駿河台２丁目',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->assertNull($facility->municipality_code);

        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);
        // The resolver memoizes names per process; the command runs in a fresh one.
        app()->forgetInstance(MunicipalityResolver::class);

        $this->artisan('facilities:assign-municipalities')
            ->expectsOutputToContain('1件の施設')
            ->assertExitCode(0);

        $facility->refresh();
        $this->assertSame('13101', $facility->municipality_code);
        $this->assertSame('2026-01-01 00:00:00', $facility->updated_at->toDateTimeString());
    }

    public function test_it_reports_facilities_it_could_not_resolve(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '存在しない市1-1']);

        $this->artisan('facilities:assign-municipalities')
            ->expectsOutputToContain('判定できなかった施設: 1件')
            ->assertExitCode(0);
    }

    public function test_it_signals_queue_workers_to_restart(): void
    {
        $this->artisan('facilities:assign-municipalities')->assertExitCode(0);

        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
    }
}
