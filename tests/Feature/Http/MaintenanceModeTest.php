<?php

namespace Tests\Feature\Http;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Production keeps maintenance mode in the database cache (.env.production.example),
 * so it outlives recreated containers. The frontends call the API from other
 * origins and show a maintenance notice when they get its 503.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
        $this->app->forgetInstance(MaintenanceMode::class);
    }

    public function test_the_api_answers_503_in_japanese_with_cors_headers_while_down(): void
    {
        $this->artisan('down')->assertSuccessful();

        $this->getJson('/api/v1/options', ['Origin' => 'https://example.github.io'])
            ->assertServiceUnavailable()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson(['message' => 'ただいまメンテナンス中です。しばらくしてから、もう一度お試しください。']);
    }

    public function test_the_state_is_kept_in_the_cache_store_shared_by_every_container(): void
    {
        $this->artisan('down')->assertSuccessful();
        $this->app->forgetInstance(MaintenanceMode::class);

        $this->assertTrue($this->app->isDownForMaintenance());
        $this->assertFileDoesNotExist(storage_path('framework/down'));
    }

    public function test_the_api_answers_again_once_up(): void
    {
        $this->artisan('down')->assertSuccessful();
        $this->artisan('up')->assertSuccessful();

        $this->getJson('/api/v1/options')->assertOk();
    }
}
