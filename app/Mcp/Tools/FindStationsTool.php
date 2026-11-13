<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Attribution;
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

#[Description('駅名から駅の位置（緯度・経度）を調べる。「〇〇駅の近く」の施設を探すときは、まずこれで位置を求め、search_facilities の latitude・longitude に渡す。同じ名前の駅が複数あれば、都道府県・市区町村・路線で見分け、分からなければ利用者に確かめる。')]
#[Name('find_stations')]
#[IsReadOnly]
#[IsIdempotent]
class FindStationsTool extends Tool
{
    public function __construct(private readonly InternalApi $api) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $result = $this->api->get('/api/v1/stations', [
            'name' => $request->get('name'),
            'prefecture_code' => $request->get('prefecture_code'),
        ]);

        if ($result['status'] !== 200) {
            return Response::error(InternalApi::errorMessage($result));
        }

        return Response::structured([
            'stations' => $result['body']['data'] ?? [],
            'attribution' => Attribution::from($result['body'], ['station_source']),
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
            'name' => $schema->string()
                ->description('駅名（例: 春日、市ヶ谷駅）。一致する駅がなければ、この語で始まる駅を返す')
                ->required(),
            'prefecture_code' => $schema->string()
                ->description('都道府県コード（JIS X 0401の2桁。例: 13 東京都、27 大阪府）。同じ名前の駅を絞り込む'),
        ];
    }
}
