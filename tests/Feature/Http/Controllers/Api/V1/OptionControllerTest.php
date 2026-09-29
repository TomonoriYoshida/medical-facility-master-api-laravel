<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_every_filter_option_as_codes_and_labels(): void
    {
        $response = $this->getJson('/api/v1/options');

        $response->assertOk();
        $response->assertJsonCount(47, 'data.prefectures');
        $response->assertJsonPath('data.prefectures.12', [
            'code' => '13',
            'label' => '東京都',
            'bureau' => ['code' => 3, 'label' => '関東信越厚生局'],
        ]);
        $response->assertJsonCount(4, 'data.institution_types');
        $response->assertJsonPath('data.institution_types.0', ['code' => 1, 'label' => '病院']);
        $response->assertJsonCount(3, 'data.statuses');
        $response->assertJsonCount(8, 'data.bureaus');
        $response->assertJsonPath('data.bureaus.1', ['code' => 2, 'label' => '東北厚生局']);
        $response->assertJsonCount(count(DepartmentBaseCategory::cases()), 'data.department_categories');
        $response->assertJsonPath('data.event_types', [
            ['code' => 1, 'label' => '新規'],
            ['code' => 2, 'label' => '廃止'],
            ['code' => 3, 'label' => '変更'],
        ]);
        $response->assertJsonPath('data.designation_reasons.0', '新規');
    }

    public function test_only_options_within_the_scope_are_returned(): void
    {
        config(['rhb.scope.prefectures' => ['02', '39'], 'rhb.scope.categories' => ['pharmacy']]);

        $response = $this->getJson('/api/v1/options');

        $response->assertOk();
        $this->assertSame(['02', '39'], $response->json('data.prefectures.*.code'));
        $this->assertSame([2, 7], $response->json('data.bureaus.*.code'));
        $response->assertJsonPath('data.institution_types', [['code' => 4, 'label' => '薬局']]);
        $response->assertJsonPath('data.department_categories', []);
    }

    public function test_attribution_lists_only_the_bureaus_in_scope(): void
    {
        config(['rhb.scope.prefectures' => ['02', '39']]);

        $response = $this->getJson('/api/v1/medical-facilities');

        $response->assertOk();
        $this->assertSame(['東北厚生局', '四国厚生局'], $response->json('meta.attribution.sources.*.bureau'));
    }

    public function test_every_prefecture_belongs_to_exactly_one_bureau(): void
    {
        $prefectures = $this->getJson('/api/v1/options')->json('data.prefectures');

        $this->assertSame(
            array_map(fn (int $number): string => sprintf('%02d', $number), range(1, 47)),
            array_column($prefectures, 'code'),
        );
        foreach ($prefectures as $prefecture) {
            $this->assertNotNull($prefecture['bureau'], "{$prefecture['label']} has no bureau");
        }
    }

    public function test_the_options_can_be_cached_by_clients_for_a_day(): void
    {
        $response = $this->getJson('/api/v1/options');

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
    }

    public function test_returned_codes_are_accepted_by_the_facility_list_filters(): void
    {
        $options = $this->getJson('/api/v1/options')->json('data');
        MedicalFacility::factory()->create([
            'prefecture_code' => $options['prefectures'][46]['code'],
            'institution_type' => InstitutionType::from($options['institution_types'][3]['code']),
        ]);

        $response = $this->getJson('/api/v1/medical-facilities?'.http_build_query([
            'prefecture_code' => $options['prefectures'][46]['code'],
            'institution_type' => $options['institution_types'][3]['code'],
            'department_category' => $options['department_categories'][0]['code'],
            'designation_reason' => $options['designation_reasons'][0],
        ]));

        $response->assertOk();
    }
}
