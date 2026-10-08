<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Shapes Scramble cannot infer from the code are written as @var in the
 * resources (and as SchemaVariant on the event resource). Client generators
 * (the demo frontend uses openapi-typescript) depend on them, so they are
 * pinned here.
 *
 * The docs are written in Japanese for the API's users. Scramble's own
 * English is replaced (App\Services\OpenApi), and an enum's docblock is
 * published as its schema description, so notes for developers go in
 * `//` comments there.
 */
class OpenApiDocumentTest extends TestCase
{
    private const array CODE_AND_INTEGER = [
        'type' => 'object',
        'properties' => ['code' => ['type' => 'integer'], 'label' => ['type' => 'string']],
        'required' => ['code', 'label'],
    ];

    public function test_facility_fields_have_their_exact_shapes(): void
    {
        $facility = $this->document()['components']['schemas']['MedicalFacilityResource']['properties'];

        $this->assertSame(['integer'], [$facility['bed_counts']['additionalProperties']['type']]);
        $this->assertSame(['object', 'null'], $facility['bed_counts']['type']);
        $this->assertSame(['reason', 'date'], $facility['designation_history']['items']['required']);
        $this->assertSame(['string', 'null'], $facility['designation_history']['items']['properties']['date']['type']);
        $this->assertSame('string', $facility['prefecture']['properties']['code']['type']);
        $this->assertSame('string', $facility['municipality']['properties']['code']['type']);
        $this->assertSame(['string', 'null'], $facility['municipality']['properties']['label']['type']);
        $this->assertSame('integer', $facility['institution_type']['properties']['code']['type']);
        $this->assertSame(self::CODE_AND_INTEGER, $facility['department_categories']['items']);
        $this->assertSame('integer', $facility['location']['properties']['level']['properties']['code']['type']);
    }

    public function test_option_lists_have_item_shapes(): void
    {
        $options = $this->document()['paths']['/v1/options']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'];

        foreach (['institution_types', 'statuses', 'bureaus', 'department_categories', 'event_types', 'geocode_levels'] as $list) {
            $this->assertSame(self::CODE_AND_INTEGER, $options[$list]['items'], $list);
        }
        $this->assertSame(['code', 'label', 'bureau'], $options['prefectures']['items']['required']);
        $this->assertSame(['type' => 'string'], $options['designation_reasons']['items']);
    }

    public function test_the_event_feed_always_includes_the_facility(): void
    {
        $document = $this->document();
        $items = fn (string $path): array => $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items'];

        $this->assertSame(['$ref' => '#/components/schemas/MedicalFacilityEventWithFacilityResource'], $items('/v1/medical-facility-events'));
        $this->assertContains('facility', $document['components']['schemas']['MedicalFacilityEventWithFacilityResource']['required']);
        $this->assertSame(['$ref' => '#/components/schemas/MedicalFacilityEventResource'], $items('/v1/medical-facilities/{medicalFacility}/events'));
        $this->assertNotContains('facility', $document['components']['schemas']['MedicalFacilityEventResource']['required']);
    }

    public function test_stats_groups_have_their_item_shape(): void
    {
        $schema = $this->document()['paths']['/v1/stats/facilities']['get']['responses']['200']['content']['application/json']['schema']['properties'];

        $this->assertSame(['key', 'label', 'count', 'population', 'count_per_10k'], $schema['data']['items']['required']);
        $this->assertSame(['integer', 'string', 'null'], $schema['data']['items']['properties']['key']['type']);
        $this->assertSame('integer', $schema['data']['items']['properties']['count']['type']);
        $this->assertSame(['integer', 'null'], $schema['data']['items']['properties']['population']['type']);
        $this->assertSame(['number', 'null'], $schema['data']['items']['properties']['count_per_10k']['type']);
        $this->assertSame('integer', $schema['meta']['properties']['total']['type']);
        $this->assertSame(['string', 'null'], $schema['meta']['properties']['population_as_of']['type']);
    }

    public function test_event_stats_groups_have_their_item_shape(): void
    {
        $schema = $this->document()['paths']['/v1/stats/facility-events']['get']['responses']['200']['content']['application/json']['schema']['properties'];

        $this->assertSame(['key', 'label', 'count'], $schema['data']['items']['required']);
        $this->assertSame(['string', 'null'], $schema['data']['items']['properties']['key']['type']);
        $this->assertSame('integer', $schema['data']['items']['properties']['count']['type']);
    }

    public function test_enum_schemas_list_their_codes_with_japanese_names(): void
    {
        $schemas = $this->document()['components']['schemas'];

        $this->assertStringStartsWith('施設の指定一覧を公開している地方厚生局。', $schemas['RhbBureau']['description']);
        $this->assertStringContainsString("\n\n| コード | 名前 |\n|---|---|\n| `1` | 北海道厚生局 |", $schemas['RhbBureau']['description']);
        $this->assertStringContainsString('| `13` | 東京都 |', $schemas['Prefecture']['description']);
        $this->assertStringContainsString('| `26` | 総合診療科 |', $schemas['DepartmentBaseCategory']['description']);
        $this->assertStringContainsString("単位\n\n| |\n|---|\n| `month` <br/> 指定年月日の月", $schemas['FacilityStatsGrouping']['description']);
    }

    public function test_facility_fields_have_examples(): void
    {
        $facility = $this->document()['components']['schemas']['MedicalFacilityResource'];

        foreach ($facility['properties'] as $name => $property) {
            $this->assertNotEmpty($property['examples'] ?? null, $name);
        }
        $this->assertSame([['code' => 1, 'label' => '病院']], $facility['properties']['institution_type']['examples']);
        $this->assertSame(['0114611'], $facility['properties']['facility_code']['examples']);
    }

    public function test_export_files_have_their_item_shape(): void
    {
        $files = $this->document()['paths']['/v1/exports']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['files'];

        $this->assertSame(['name', 'format', 'prefecture', 'records', 'size', 'sha256', 'url'], $files['items']['required']);
        $this->assertSame(['csv', 'jsonl'], $files['items']['properties']['format']['enum']);
        $this->assertSame(['object', 'null'], $files['items']['properties']['prefecture']['type']);
    }

    public function test_responses_written_in_the_controllers_have_examples(): void
    {
        $paths = $this->document()['paths'];
        $data = fn (string $path): array => $paths[$path]['get']['responses']['200']['content']['application/json']['schema']['properties']['data'];

        $this->assertNotEmpty($data('/v1/stats/facilities')['examples']);
        $this->assertNotEmpty($data('/v1/stats/facility-events')['examples']);
        $this->assertNotEmpty($data('/v1/holidays')['examples']);
        $this->assertNotEmpty($data('/v1/exports')['properties']['files']['examples']);
        $this->assertNotEmpty($data('/v1/medical-facilities/{medicalFacility}/opening-hours')['properties']['schedules']['examples']);

        foreach ($data('/v1/options')['properties'] as $name => $list) {
            $this->assertNotEmpty($list['examples'] ?? null, $name);
        }
    }

    public function test_scramble_descriptions_are_in_japanese(): void
    {
        $document = $this->document();
        $events = $document['paths']['/v1/medical-facility-events']['get'];

        $this->assertSame('1ページあたりの件数', $events['responses']['200']['content']['application/json']['schema']['properties']['meta']['properties']['per_page']['description']);
        $this->assertSame('パラメータの誤り', $document['components']['responses']['ValidationException']['description']);
        $this->assertSame('`MedicalFacilityResource` のページ', $document['paths']['/v1/medical-facilities']['get']['responses']['200']['description']);
        $this->assertSame('施設のID（施設一覧の `id`）', $document['paths']['/v1/medical-facilities/{medicalFacility}']['get']['parameters'][0]['description']);
    }

    /**
     * Code spans are left out: they hold parameter names and values.
     */
    public function test_no_description_is_written_in_english(): void
    {
        foreach ($this->descriptions($this->document()) as $path => $description) {
            $prose = preg_replace('/`[^`]*`/', '', $description);

            $this->assertDoesNotMatchRegularExpression('/\b[A-Za-z]{3,}(?: [A-Za-z]{2,}){2,}/', $prose, $path);
        }
    }

    public function test_lines_wrapped_in_japanese_text_are_joined(): void
    {
        $description = $this->document()['paths']['/v1/medical-facilities/{medicalFacility}/opening-hours']['get']['description'];

        $this->assertStringContainsString('ときに返し、見つからないときは', $description);
        $this->assertStringContainsString("含みません。\n\n`schedules` は", $description);
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, string> Every description and summary, by its path in the document.
     */
    private function descriptions(array $node, string $path = ''): array
    {
        $descriptions = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $descriptions += $this->descriptions($value, "{$path}/{$key}");
            } elseif (in_array($key, ['description', 'summary'], true) && is_string($value)) {
                $descriptions["{$path}/{$key}"] = $value;
            }
        }

        return $descriptions;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return $this->getJson(route('scramble.docs.document'))->assertOk()->json();
    }
}
