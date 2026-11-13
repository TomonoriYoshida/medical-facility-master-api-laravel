<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Attribution;
use App\Mcp\Support\FacilitySummary;
use App\Mcp\Support\InternalApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('施設の詳細と、診療時間・定休日を返す（id は search_facilities の結果のもの）。診療時間は厚生労働省「医療情報ネット」の公開時点（published_on）の情報で、臨時の休診や最近の変更は含まない。照合できない施設は opening_hours が null。')]
#[Name('get_facility')]
#[IsReadOnly]
#[IsIdempotent]
class GetFacilityTool extends Tool
{
    public function __construct(private readonly InternalApi $api) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $facility = $this->api->get("/api/v1/medical-facilities/{$validated['id']}");

        if ($facility['status'] !== 200) {
            return Response::error(InternalApi::errorMessage($facility));
        }

        // Looked up by the facility's ID again (binding it a second time),
        // which keeps the two API responses exactly as the API gives them.
        $hours = $this->api->get("/api/v1/medical-facilities/{$validated['id']}/opening-hours");

        if ($hours['status'] !== 200) {
            return Response::error(InternalApi::errorMessage($hours));
        }

        $data = $facility['body']['data'] ?? [];

        return Response::structured([
            'facility' => [
                ...FacilitySummary::from($data),
                'name_kana' => $data['name_kana'] ?? null,
                'status' => $data['status']['label'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'bed_counts' => $data['bed_counts'] ?? null,
                'e_prescription' => $data['e_prescription'] ?? null,
            ],
            'opening_hours' => $hours['body']['data'] ?? null,
            'attribution' => Attribution::from($facility['body'], ['address_source', 'medical_info_net_source', 'national_land_source', 'e_prescription_source']),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('施設のID（search_facilities の結果の id）')
                ->required(),
        ];
    }
}
