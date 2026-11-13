<?php

namespace Tests\Feature\Http;

use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * In production the web server connects as a user that may only read, and
 * write the cache table (rate limiter counters, cached stats and totals,
 * maintenance mode): see DEPLOY.md. A web request that writes anything else
 * would fail there with an access-denied error.
 */
class WebRequestDatabaseWritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_requests_write_only_the_cache_table(): void
    {
        Storage::fake('local');
        config([
            'cache.default' => 'database',
            'session.driver' => 'database',
            'queue.default' => 'database',
            'rhb.scope.prefectures' => ['13'],
        ]);
        $facility = MedicalFacility::factory()->create(['prefecture_code' => '13']);
        MedicalFacilityEvent::factory()->for($facility)->create();
        $this->artisan('rhb:export')->assertExitCode(0);

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate|rename|grant)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $uris = [
            '/',
            '/up',
            '/docs/api',
            '/docs/api.json',
            '/api/v1/options',
            '/api/v1/holidays',
            '/api/v1/stations?name=春日',
            '/api/v1/medical-facilities',
            '/api/v1/medical-facilities?q=病院&total=capped',
            '/api/v1/medical-facilities?pagination=cursor',
            '/api/v1/medical-facilities?latitude=35.68&longitude=139.76&radius=5000&open_at=2026-10-13T10:00:00%2B09:00',
            "/api/v1/medical-facilities/{$facility->id}",
            "/api/v1/medical-facilities/{$facility->id}/events",
            "/api/v1/medical-facilities/{$facility->id}/opening-hours",
            '/api/v1/medical-facility-events',
            '/api/v1/stats/facilities?group_by=municipality',
            '/api/v1/stats/facility-events?group_by=municipality&event_type=1',
            '/api/v1/exports',
            '/api/v1/exports/medical-facilities-13.csv.gz',
        ];

        foreach ($uris as $uri) {
            $this->assertLessThan(400, $this->get($uri)->getStatusCode(), "{$uri} failed");
        }

        // The MCP endpoint, which runs the API's controllers in-process.
        $mcpHeaders = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25'];
        foreach ([
            ['method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]],
            ['method' => 'tools/call', 'params' => ['name' => 'search_facilities', 'arguments' => ['keyword' => '病院', 'open_at' => '2026-10-13T10:00']]],
            ['method' => 'tools/call', 'params' => ['name' => 'get_facility', 'arguments' => ['id' => $facility->id]]],
            ['method' => 'tools/call', 'params' => ['name' => 'find_stations', 'arguments' => ['name' => '春日']]],
        ] as $index => $message) {
            $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $index, ...$message], $mcpHeaders);
            $response->assertOk();
            $this->assertNotTrue($response->json('result.isError'), "{$message['method']} failed");
        }

        $this->assertNotEmpty($writes, 'the rate limiter should have written the cache table');
        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(insert( ignore)? into|update|delete from)\s+`?cache`?\s/i', $sql, "a web request wrote outside the cache table: {$sql}");
        }
    }
}
