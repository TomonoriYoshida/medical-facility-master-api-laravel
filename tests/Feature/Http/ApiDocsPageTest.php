<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * The docs UI is Scramble's view overridden in resources/views/vendor/scramble
 * to declare the page Japanese and give it a description and link preview.
 */
class ApiDocsPageTest extends TestCase
{
    public function test_the_docs_page_is_declared_japanese(): void
    {
        $this->get(route('scramble.docs.ui'))
            ->assertOk()
            ->assertSee('<html lang="ja"', escape: false);
    }

    public function test_the_docs_page_has_a_description_and_a_link_preview(): void
    {
        $response = $this->get(route('scramble.docs.ui'));

        $response->assertOk()
            ->assertSee('<meta name="description" content="地方厚生局の「保険医療機関・保険薬局の指定一覧」をもとにした', escape: false)
            ->assertSee('<meta property="og:title" content="医療施設マスタAPI">', escape: false)
            ->assertSee('<meta property="og:url" content="'.route('scramble.docs.ui').'">', escape: false);
    }

    public function test_the_docs_page_still_embeds_the_openapi_document(): void
    {
        $this->get(route('scramble.docs.ui'))
            ->assertOk()
            ->assertSee('<elements-api', escape: false)
            ->assertSee('docs.apiDescriptionDocument = ', escape: false);
    }
}
