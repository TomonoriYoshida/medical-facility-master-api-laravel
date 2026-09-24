<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    public function test_the_root_url_redirects_to_the_api_docs(): void
    {
        $this->get('/')->assertRedirect(route('scramble.docs.ui'));
    }
}
