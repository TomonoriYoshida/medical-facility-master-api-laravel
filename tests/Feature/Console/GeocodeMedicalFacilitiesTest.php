<?php

namespace Tests\Feature\Console;

use App\Enums\GeocodeLevel;
use App\Models\MedicalFacility;
use App\Models\Municipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesAbrDatasets;
use Tests\TestCase;

class GeocodeMedicalFacilitiesTest extends TestCase
{
    use FakesAbrDatasets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['rhb.scope.prefectures' => ['13']]);
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);

        $this->fakeAbr([
            'mt_town/pref/mt_town_pref13.csv.zip' => [
                $this->abrTown('131016', '0001001', '内幸町', chome: '1', residential: true),
                $this->abrTown('131016', '0001002', '内幸町', chome: '2', residential: true),
            ],
            'mt_town_pos/pref/mt_town_pos_pref13.csv.zip' => [
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0001001'], 35.670001, 139.750001),
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0001002'], 35.680002, 139.760002),
            ],
        ]);
    }

    public function test_it_locates_facilities_and_marks_them_as_changed(): void
    {
        $facility = MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'address' => '千代田区内幸町一丁目5番1号',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $unlocatable = MedicalFacility::factory()->create([
            'prefecture_code' => '13',
            'address' => '千代田区存在しない町1',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->artisan('facilities:geocode')
            ->expectsOutputToContain('町丁目 1 / 判定不能 1')
            ->assertExitCode(0);

        $facility->refresh();
        $this->assertSame('35.670001', $facility->latitude);
        $this->assertSame('139.750001', $facility->longitude);
        $this->assertSame(GeocodeLevel::Town, $facility->geocode_level);
        $this->assertSame('千代田区内幸町一丁目5番1号', $facility->geocoded_address);
        $this->assertNotSame('2026-01-01 00:00:00', $facility->updated_at->toDateTimeString());

        // Marked as geocoded so it is not retried daily, but nothing changed.
        $unlocatable->refresh();
        $this->assertNull($unlocatable->latitude);
        $this->assertSame('千代田区存在しない町1', $unlocatable->geocoded_address);
        $this->assertSame('2026-01-01 00:00:00', $unlocatable->updated_at->toDateTimeString());
    }

    public function test_facilities_already_geocoded_are_skipped_unless_all(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区内幸町一丁目5番1号']);
        $this->artisan('facilities:geocode')->assertExitCode(0);
        Http::fake();

        $this->artisan('facilities:geocode')->assertExitCode(0);
        Http::assertNothingSent();

        $this->artisan('facilities:geocode', ['--all' => true])
            ->expectsOutputToContain('町丁目 1')
            ->assertExitCode(0);
    }

    public function test_an_unchanged_location_leaves_updated_at_alone(): void
    {
        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区内幸町一丁目5番1号']);
        $this->artisan('facilities:geocode')->assertExitCode(0);
        MedicalFacility::query()->whereKey($facility->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);

        $this->artisan('facilities:geocode', ['--all' => true])->assertExitCode(0);

        $this->assertSame('2026-01-01 00:00:00', $facility->refresh()->updated_at->toDateTimeString());
    }

    public function test_a_moved_facility_loses_its_old_location_until_geocoded_again(): void
    {
        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区内幸町一丁目5番1号']);
        $this->artisan('facilities:geocode')->assertExitCode(0);

        $facility->refresh()->update(['address' => '千代田区内幸町二丁目1番1号']);
        $this->assertNull($facility->latitude);
        $this->assertNull($facility->geocode_level);
        $this->assertNull($facility->geocoded_address);

        $this->artisan('facilities:geocode')->assertExitCode(0);

        $this->assertSame('35.680002', $facility->refresh()->latitude);
    }

    public function test_a_width_only_address_change_is_geocoded_again(): void
    {
        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区内幸町一丁目5番1号']);
        $this->artisan('facilities:geocode')->assertExitCode(0);

        // Equal to the old address under the column's collation.
        $facility->refresh()->update(['address' => '千代田区内幸町一丁目５番１号']);
        $this->artisan('facilities:geocode')->assertExitCode(0);

        $this->assertSame('35.670001', $facility->refresh()->latitude);
    }

    public function test_an_address_changed_while_geocoding_is_not_given_the_old_location(): void
    {
        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区内幸町一丁目5番1号']);

        // The import's queue worker moves the facility (width only) mid-run.
        $this->onAbrRequest = function () use ($facility): void {
            $this->onAbrRequest = null;
            $facility->refresh()->update(['address' => '千代田区内幸町一丁目５番１号']);
        };

        $this->artisan('facilities:geocode')->assertExitCode(0);

        $facility->refresh();
        $this->assertNull($facility->latitude);
        $this->assertNull($facility->geocoded_address);

        $this->artisan('facilities:geocode')->assertExitCode(0);
        $this->assertSame('35.670001', $facility->refresh()->latitude);
    }

    public function test_a_download_failure_is_reported(): void
    {
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'address' => '千代田区内幸町一丁目5番1号']);
        // Replaces setUp()'s fake, which would otherwise answer first.
        Http::swap(new Factory);
        Http::fake(fn () => Http::response('', 500));

        $this->artisan('facilities:geocode')
            ->expectsOutputToContain('東京都')
            ->assertExitCode(1);
    }
}
