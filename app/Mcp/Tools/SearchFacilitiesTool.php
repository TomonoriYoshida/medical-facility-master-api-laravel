<?php

namespace App\Mcp\Tools;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\Prefecture;
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

#[Description('指定中（廃止・休止を除く）の病院・診療所・歯科診療所・薬局を探す。場所（latitude・longitude と radius、または keyword の地名・prefecture_code）、診療科、施設種別、受付中の日時（open_at）で絞り込める。位置を指定すると近い順で、各施設に距離（distance、メートル）が付く。open_at を指定すると、その日時に受付中の施設だけを返し、受付が終わる日時（open_until）が付く。travel（walk / car）を付けると open_at を出発時刻として各施設への到着の目安（arrives_at）で判定し、wait を付けると到着からその分数以内に受付が始まる施設も含める（受付が始まる日時 opens_at が付く）。詳しい診療時間は get_facility で調べる。')]
#[Name('search_facilities')]
#[IsReadOnly]
#[IsIdempotent]
class SearchFacilitiesTool extends Tool
{
    /** Fewer than the API's, to keep the results short for the model. */
    private const int MAX_PER_PAGE = 20;

    private const int MAX_RADIUS = 10000;

    public function __construct(private readonly InternalApi $api) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'radius' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_RADIUS],
            'travel' => ['nullable', 'in:walk,car'],
            'wait' => ['nullable', 'integer', 'min:0', 'max:60'],
        ], [
            'per_page.max' => 'per_page は'.self::MAX_PER_PAGE.'以下にしてください。',
            'radius.max' => 'radius は'.self::MAX_RADIUS.'メートル以下にしてください。',
            'travel.in' => 'travel は walk（徒歩）か car（車）にしてください。',
            'wait.min' => 'wait は0〜60（分）にしてください。',
            'wait.max' => 'wait は0〜60（分）にしてください。',
        ]);

        // Both judge around a departure place and time; say so before the API does.
        if ((isset($validated['travel']) || isset($validated['wait']))
            && ($request->get('open_at') === null || $request->get('latitude') === null || $request->get('longitude') === null)) {
            return Response::error('travel と wait は、open_at（出発の日時）と latitude・longitude（出発地点）と一緒に指定してください。地名からなら find_stations で位置を求めます。');
        }

        $result = $this->api->get('/api/v1/medical-facilities', [
            'q' => $request->get('keyword'),
            'prefecture_code' => $request->get('prefecture_code'),
            'institution_type' => $request->get('institution_type'),
            'department_category' => $request->get('department_category'),
            'open_at' => $request->get('open_at'),
            'travel' => $validated['travel'] ?? null,
            'wait' => $validated['wait'] ?? null,
            'latitude' => $request->get('latitude'),
            'longitude' => $request->get('longitude'),
            'radius' => $validated['radius'] ?? null,
            'per_page' => $validated['per_page'] ?? 10,
            'page' => $request->get('page'),
            'status' => MedicalFacilityStatus::Active->value,
            // The model only needs the size; counting all of a broad search is slow.
            'total' => 'capped',
        ]);

        if ($result['status'] !== 200) {
            return Response::error(InternalApi::errorMessage($result));
        }

        $meta = $result['body']['meta'] ?? [];

        return Response::structured([
            'total' => $meta['total'] ?? null,
            'total_is_capped' => $meta['total_is_capped'] ?? false,
            'page' => $meta['current_page'] ?? null,
            'last_page' => $meta['last_page'] ?? null,
            'facilities' => array_map($this->facility(...), $result['body']['data'] ?? []),
            'attribution' => Attribution::from($result['body'], ['address_source', 'medical_info_net_source', 'national_land_source']),
        ]);
    }

    /**
     * A facility in short, with what the search adds to it.
     *
     * @param  array<string, mixed>  $facility
     * @return array<string, mixed>
     */
    private function facility(array $facility): array
    {
        return array_filter([
            ...FacilitySummary::from($facility),
            'distance' => $facility['distance'] ?? null,
            'arrives_at' => $facility['arrives_at'] ?? null,
            'opens_at' => $facility['opens_at'] ?? null,
            'open_until' => $facility['open_until'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()
                ->description('施設名・住所に含まれる語（表記ゆれを吸収）。空白で区切るとすべてを含む施設（例: 文京区 眼科、最大5語）'),
            'prefecture_code' => $schema->string()
                ->enum(array_map(fn (Prefecture $prefecture): string => $prefecture->value, Prefecture::cases()))
                ->description('都道府県コード: '.$this->codes(Prefecture::cases())),
            'institution_type' => $schema->integer()
                ->enum(array_map(fn (InstitutionType $type): int => $type->value, InstitutionType::cases()))
                ->description('施設種別: '.$this->codes(InstitutionType::cases())),
            'department_category' => $schema->integer()
                ->enum(array_map(fn (DepartmentBaseCategory $category): int => $category->value, DepartmentBaseCategory::cases()))
                ->description('診療科（大分類）: '.$this->codes(DepartmentBaseCategory::cases()).'。「小児皮膚科」なども大分類（皮膚科）で探す'),
            'open_at' => $schema->string()
                ->description('この日時に受付中の施設だけを返す（ISO 8601。時差がなければ日本時間。例: 2026-11-14T15:00）。「いま」「土曜の午後」などは、日本時間の具体的な日時にして渡す'),
            'travel' => $schema->string()
                ->enum(['walk', 'car'])
                ->description('移動手段（walk: 徒歩、car: 車）。open_at と latitude・longitude が必要。open_at を出発時刻として、各施設に着くころ（直線距離からの目安）に受付中かで判定する。「歩いて行ける」「これから車で向かう」ときに使う'),
            'wait' => $schema->integer()
                ->min(0)
                ->max(60)
                ->description('到着から何分以内に受付が始まる施設も含めるか（0〜60）。open_at と latitude・longitude が必要。開く前の朝方など、少し待ってもよいときに使う'),
            'latitude' => $schema->number()
                ->description('検索地点の緯度。longitude と組み合わせ、radius 以内を近い順に返す'),
            'longitude' => $schema->number()
                ->description('検索地点の経度'),
            'radius' => $schema->integer()
                ->description('検索半径（メートル、既定1000、最大'.self::MAX_RADIUS.'）'),
            'per_page' => $schema->integer()
                ->description('1ページの件数（既定10、最大'.self::MAX_PER_PAGE.'）'),
            'page' => $schema->integer()
                ->description('ページ番号（既定1）'),
        ];
    }

    /**
     * "01 北海道, 02 青森県, …" for a schema description.
     *
     * @param  list<Prefecture|InstitutionType|DepartmentBaseCategory>  $cases
     */
    private function codes(array $cases): string
    {
        return implode(', ', array_map(fn (Prefecture|InstitutionType|DepartmentBaseCategory $case): string => $case->value.' '.$case->label(), $cases));
    }
}
