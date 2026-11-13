<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Prefecture;
use App\Http\Controllers\Api\V1\Concerns\EscapesLikePatterns;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RailwayStationIndexRequest;
use App\Models\RailwayStation;
use App\Services\Address\MunicipalityResolver;
use App\Services\Text\StationNameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

class RailwayStationController extends Controller
{
    use EscapesLikePatterns;
    use ProvidesAttribution;

    /** The most stations returned, as a name like "中央" starts many. */
    private const int MAX_STATIONS = 20;

    /**
     * The most rows (lines) read for a name's prefix: the shortest names
     * come first, so MAX_STATIONS stations are within them.
     */
    private const int MAX_PREFIX_ROWS = 200;

    public function __construct(
        private readonly StationNameNormalizer $names,
        private readonly MunicipalityResolver $municipalities,
    ) {}

    /**
     * 駅の検索
     *
     * 駅名から駅の位置を返します。一覧APIの `latitude`・`longitude` に渡すと、駅の近くの施設を検索できます。
     * 複数の路線が乗り入れる駅は1件にまとめ、`lines` に路線を並べます。同じ名前の駅が各地にあるときは、
     * `prefecture`・`municipality`（駅の近くの施設から判定）と路線で見分けてください。
     * 国土交通省「国土数値情報（鉄道データ）」の駅の位置（ホームの中央）です。
     */
    public function __invoke(RailwayStationIndexRequest $request): JsonResponse
    {
        $name = $this->names->normalize((string) $request->validated('name'));
        $prefectureCode = $request->validated('prefecture_code');

        // "市ヶ谷駅" is 市ケ谷, but "松山市駅" is a station's own name, so the
        // name is tried as given first, then without the suffix, and only
        // then as a prefix (or "高知駅" would find 高知駅前 before 高知).
        $stations = $this->stations($prefectureCode)->where('name_normalized', $name)->get();

        if ($stations->isEmpty() && mb_strlen($name) > 1 && str_ends_with($name, '駅')) {
            $stations = $this->stations($prefectureCode)->where('name_normalized', mb_substr($name, 0, -1))->get();
        }

        if ($stations->isEmpty()) {
            $stations = $this->stations($prefectureCode)
                ->where('name_normalized', 'like', $this->escapeLike($name).'%')
                ->reorder()
                ->orderByRaw('CHAR_LENGTH(name_normalized)')
                ->orderBy('station_code')
                ->limit(self::MAX_PREFIX_ROWS)
                ->get();
        }

        return response()->json([
            /**
             * 一致した駅（最大20件）。名前が完全に一致した駅を返し、なければその語で始まる駅を名前の短い順に返す（同じ長さなら都道府県コード順）。
             * `latitude`・`longitude` は駅の位置（路線ごとのホームの中央の平均）、`lines` は乗り入れる路線（`operator` は事業者、`line` は路線名）。
             * `prefecture`・`municipality` は駅の近く（約3km以内）の施設から判定したもので、近くに施設がなければ `null`
             *
             * @var list<array{name: string, prefecture: array{code: string, label: string}|null, municipality: array{code: string, label: string|null}|null, latitude: float, longitude: float, lines: list<array{operator: string, line: string}>}>
             *
             * @example [{"name": "春日", "prefecture": {"code": "13", "label": "東京都"}, "municipality": {"code": "13105", "label": "文京区"}, "latitude": 35.709235, "longitude": 139.75251, "lines": [{"operator": "東京都", "line": "6号線三田線"}, {"operator": "東京都", "line": "12号線大江戸線"}]}]
             */
            'data' => $stations
                ->groupBy('group_code')
                ->map(fn (Collection $group): array => $this->station($group))
                ->sortBy([
                    fn (array $a, array $b): int => mb_strlen($a['name']) <=> mb_strlen($b['name']),
                    fn (array $a, array $b): int => ($a['prefecture']['code'] ?? '99') <=> ($b['prefecture']['code'] ?? '99'),
                    fn (array $a, array $b): int => $a['name'] <=> $b['name'],
                ])
                ->take(self::MAX_STATIONS)
                ->values(),
            'meta' => ['attribution' => $this->attribution()],
        ]);
    }

    /**
     * Stations, of the given prefecture if any. A group's lines share
     * their prefecture (RailwayStationImporter), so a station is never
     * returned with only some of its lines.
     *
     * @return Builder<RailwayStation>
     */
    private function stations(?string $prefectureCode): Builder
    {
        return RailwayStation::query()
            ->when($prefectureCode, fn ($query, $code) => $query->where('prefecture_code', $code))
            ->orderBy('station_code');
    }

    /**
     * One station from the rows of its lines.
     *
     * @param  Collection<int, RailwayStation>  $lines
     * @return array<string, mixed>
     */
    private function station(Collection $lines): array
    {
        // A group always has at least one line.
        $first = $lines->firstOrFail();
        $prefecture = $first->prefecture_code === null ? null : Prefecture::tryFrom($first->prefecture_code);

        return [
            'name' => $first->name,
            'prefecture' => $prefecture === null ? null : ['code' => $prefecture->value, 'label' => $prefecture->label()],
            'municipality' => $first->municipality_code === null ? null : [
                'code' => $first->municipality_code,
                'label' => $this->municipalities->label($first->municipality_code),
            ],
            'latitude' => round($lines->sum(fn (RailwayStation $line): float => (float) $line->latitude) / $lines->count(), 6),
            'longitude' => round($lines->sum(fn (RailwayStation $line): float => (float) $line->longitude) / $lines->count(), 6),
            'lines' => $lines
                ->map(fn (RailwayStation $line): array => ['operator' => $line->operator_name, 'line' => $line->line_name])
                ->values()
                ->all(),
        ];
    }
}
