<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The API's error messages are in Japanese (lang/ja) and name a parameter
 * as it is written in the query.
 */
class ApiErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_errors_are_in_japanese_with_parameter_names_as_is(): void
    {
        $this->getJson('/api/v1/medical-facilities?per_page=abc&prefecture_code=99&latitude=35')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'prefecture_code の値が正しくありません。 （ほか 2 件のエラー）')
            ->assertJsonPath('errors.per_page.0', 'per_page は整数にしてください。')
            ->assertJsonPath('errors.longitude.0', 'latitude を指定するときは、longitude も指定してください。');
    }

    public function test_a_rule_comparing_two_parameters_names_the_other_one_as_is(): void
    {
        $this->getJson('/api/v1/holidays?from=2026-12-01&to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'to は from 以降の日付にしてください。');
    }

    public function test_a_date_in_the_wrong_format_shows_an_example(): void
    {
        $this->getJson('/api/v1/holidays?from=2026/10/01')
            ->assertUnprocessable()
            ->assertJsonPath('errors.from.0', 'from は Y-m-d の形式にしてください（例: 2026-10-01）。');
    }

    public function test_too_many_search_words_are_reported_in_japanese(): void
    {
        $this->getJson('/api/v1/medical-facilities?q='.urlencode('a b c d e f'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.q.0', 'q の語は5語以内にしてください。');
    }

    public function test_a_method_other_than_get_is_rejected_in_japanese(): void
    {
        $this->postJson('/api/v1/medical-facilities')
            ->assertMethodNotAllowed()
            ->assertExactJson(['message' => 'このURLは GET だけに対応しています。']);
    }
}
