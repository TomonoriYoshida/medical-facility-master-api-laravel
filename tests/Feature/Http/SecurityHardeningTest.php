<?php

namespace Tests\Feature\Http;

use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_docs_and_root_pages_start_no_session_and_set_no_cookies(): void
    {
        foreach (['/', '/docs/api', '/docs/api.json'] as $uri) {
            $response = $this->get($uri);

            $this->assertSame([], $response->headers->getCookies(), "{$uri} sets cookies");
        }
    }

    public function test_the_docs_are_rate_limited(): void
    {
        config(['api.docs_rate_limit_per_minute' => 1]);

        $this->getJson('/docs/api.json')->assertOk()->assertHeader('X-RateLimit-Limit', 1);
        $this->getJson('/docs/api.json')->assertTooManyRequests();
    }

    public function test_bulk_downloads_have_their_own_hourly_limit(): void
    {
        Storage::fake('local');
        config(['rhb.scope.prefectures' => ['13'], 'api.export_downloads_per_hour' => 1]);
        MedicalFacility::factory()->create(['prefecture_code' => '13']);
        $this->artisan('rhb:export')->assertExitCode(0);

        $this->get('/api/v1/exports/medical-facilities-13.csv.gz')->assertOk();
        $this->get('/api/v1/exports/medical-facilities-13.csv.gz')->assertTooManyRequests();
        $this->getJson('/api/v1/exports')->assertOk();
    }

    public function test_not_found_responses_do_not_reveal_internal_class_names(): void
    {
        $model = $this->getJson('/api/v1/medical-facilities/999999');
        $route = $this->getJson('/api/v1/no-such-endpoint');

        $model->assertNotFound()->assertExactJson(['message' => '見つかりません。']);
        $route->assertNotFound()->assertExactJson(['message' => '見つかりません。']);
    }

    public function test_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/v1/options');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $response = $this->getJson('https://localhost/api/v1/options');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_local_disk_files_are_not_served_over_http(): void
    {
        $this->get('/storage/exports/manifest.json')->assertNotFound();
    }
}
