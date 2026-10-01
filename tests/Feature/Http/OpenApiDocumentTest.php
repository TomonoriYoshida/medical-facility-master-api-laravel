<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Shapes Scramble cannot infer from the code are written as @var in the
 * resources (and as SchemaVariant on the event resource). Client generators
 * (the demo frontend uses openapi-typescript) depend on them, so they are
 * pinned here.
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

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return $this->getJson(route('scramble.docs.document'))->assertOk()->json();
    }
}
