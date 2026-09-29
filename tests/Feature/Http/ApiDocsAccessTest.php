<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Scramble lets anyone through in the local environment and consults the
 * viewApiDocs gate everywhere else, so these run outside "local" (tests run
 * as "testing") to exercise the gate the way production does.
 */
class ApiDocsAccessTest extends TestCase
{
    public function test_guests_can_view_the_api_docs_ui(): void
    {
        $this->get(route('scramble.docs.ui'))->assertOk();
    }

    public function test_guests_can_download_the_openapi_document(): void
    {
        $this->getJson(route('scramble.docs.document'))
            ->assertOk()
            ->assertJsonPath('openapi', fn (string $version): bool => str_starts_with($version, '3.'));
    }
}
