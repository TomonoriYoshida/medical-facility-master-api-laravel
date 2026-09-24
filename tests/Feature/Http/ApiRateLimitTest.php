<?php

namespace Tests\Feature\Http;

use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.rate_limit_per_minute' => 2]);
    }

    public function test_requests_beyond_the_per_minute_limit_are_rejected_with_429(): void
    {
        $this->getJson(route('api.v1.medical-facilities.index'))
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 1);
        $this->getJson(route('api.v1.medical-facilities.index'))->assertOk();

        $this->getJson(route('api.v1.medical-facilities.index'))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message']);
    }

    public function test_the_limit_is_shared_across_api_endpoints(): void
    {
        $facility = MedicalFacility::factory()->create();

        $this->getJson(route('api.v1.medical-facilities.index'))->assertOk();
        $this->getJson(route('api.v1.medical-facilities.show', $facility))->assertOk();

        $this->getJson(route('api.v1.medical-facilities.show', $facility))->assertTooManyRequests();
    }

    public function test_each_client_ip_has_its_own_limit(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
        $this->getJson(route('api.v1.medical-facilities.index'))->assertOk();
        $this->getJson(route('api.v1.medical-facilities.index'))->assertOk();
        $this->getJson(route('api.v1.medical-facilities.index'))->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2']);
        $this->getJson(route('api.v1.medical-facilities.index'))->assertOk();
    }
}
