<?php

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockScannersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.scanner_block.store' => 'array', 'api.scanner_block.hours' => 24]);
    }

    public function test_a_client_probing_for_a_scanner_path_is_turned_away_afterwards(): void
    {
        $this->getJson('/api/v1/options')->assertOk();

        // The probe itself looks like any unknown path.
        $this->get('/.env')->assertNotFound();

        $this->getJson('/api/v1/options', ['Origin' => 'https://example.com'])
            ->assertForbidden()
            ->assertJsonPath('message', 'アクセスが制限されています。')
            // A browser client can still read why.
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/docs/api')->assertForbidden();
    }

    public function test_other_clients_are_not_affected(): void
    {
        $this->get('/wp-login.php')->assertNotFound();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v1/options')
            ->assertOk();
    }

    public function test_the_block_ends_after_the_configured_hours(): void
    {
        $this->get('/.git/config')->assertNotFound();

        $this->travel(23)->hours();
        $this->getJson('/api/v1/options')->assertForbidden();

        $this->travel(2)->hours();
        $this->getJson('/api/v1/options')->assertOk();
    }

    public function test_unknown_paths_a_visitor_might_ask_for_do_not_block(): void
    {
        $this->get('/README.md')->assertNotFound();
        $this->getJson('/api/v1/medical-facilities/999999')->assertNotFound();

        $this->getJson('/api/v1/options')->assertOk();
    }

    public function test_blocking_can_be_turned_off(): void
    {
        config(['api.scanner_block.hours' => 0]);

        $this->get('/.env')->assertNotFound();

        $this->getJson('/api/v1/options')->assertOk();
    }
}
