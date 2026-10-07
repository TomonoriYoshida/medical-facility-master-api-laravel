<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersMedicalFacilities;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityIndexRequest;
use App\Http\Resources\Api\V1\MedicalFacilityResource;
use App\Models\MedicalFacility;
use App\Models\PublicHoliday;
use App\Services\MedicalInfoNet\OpeningPeriods;
use App\Services\Text\AddressNormalizer;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MedicalFacilityController extends Controller
{
    use FiltersMedicalFacilities;
    use ProvidesAttribution;

    private const int DEFAULT_RADIUS = 1000;

    /** Meters per degree of latitude (and of longitude at the equator). */
    private const float METERS_PER_DEGREE = 111_320;

    public function __construct(
        private readonly ItaijiNormalizer $itaijiNormalizer,
        private readonly AddressNormalizer $addressNormalizer,
    ) {}

    /**
     * 施設一覧・検索
     *
     * 医療施設マスタをページネーション付きで返します。`q` は施設名・住所の全角半角/異体字ゆれを
     * 吸収したあいまい検索です。`latitude`・`longitude` を指定すると、`radius` 以内の施設を近い順に返します。
     * `open_at` を指定すると、その日時に受付中の施設（厚生労働省「医療情報ネット」の診療時間で判定）だけを返します。
     */
    public function index(MedicalFacilityIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $facilities = $this->facilities($filters, forCounting: false);

        // withQueryString(): the links.next a client follows must carry the
        // same filters (and, for a cursor, pagination=cursor itself).
        if ($request->usesCursor()) {
            return MedicalFacilityResource::collection($facilities->cursorPaginate($request->perPage())->withQueryString())
                ->additional(['meta' => ['attribution' => $this->attribution()]]);
        }

        $totalCap = $request->totalCap();
        $paginator = $facilities->paginate($request->perPage(), total: function () use ($filters, $totalCap): int {
            $counted = $this->facilities($filters, forCounting: true);

            return $totalCap === null ? $counted->toBase()->getCountForPagination() : $this->countUpTo($counted, $totalCap);
        });

        return MedicalFacilityResource::collection($paginator->withQueryString())
            ->additional(['meta' => [
                'max_page' => $request->maxPage(),
                'total_is_capped' => $this->isCapped($paginator->total(), $totalCap),
                'attribution' => $this->attribution(),
            ]]);
    }

    /**
     * 施設詳細
     *
     * idを指定して医療施設マスタの1件を返します。
     *
     * @param  MedicalFacility  $medicalFacility  施設のID（施設一覧の `id`）
     */
    public function show(MedicalFacility $medicalFacility): MedicalFacilityResource
    {
        return (new MedicalFacilityResource($medicalFacility))
            ->additional(['meta' => ['attribution' => $this->attribution()]]);
    }

    /**
     * The facilities matching the list's filters, in the requested order.
     * Counting them and fetching a page of them can call for different
     * query plans; see applyOpenAt().
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<MedicalFacility>
     */
    private function facilities(array $filters, bool $forCounting): Builder
    {
        return $this->applyFilters(MedicalFacility::query(), $filters)
            ->when($filters['q'] ?? null, fn ($query, $term) => $this->applySearch($query, $term))
            ->when($filters['open_at'] ?? null, fn ($query, $value) => $this->applyOpenAt($query, (string) $value, $forCounting))
            ->when(isset($filters['latitude'], $filters['longitude']), fn ($query) => $this->applyNearby(
                $query,
                (float) $filters['latitude'],
                (float) $filters['longitude'],
                (int) ($filters['radius'] ?? self::DEFAULT_RADIUS),
                orderByDistance: ! isset($filters['sort']),
            ))
            ->when(
                $filters['sort'] ?? null,
                fn ($query, $sort) => match ($sort) {
                    'designated_on' => $query->orderBy('designated_on'),
                    '-designated_on' => $query->orderByDesc('designated_on'),
                    'updated_at' => $query->orderBy('updated_at'),
                    '-updated_at' => $query->orderByDesc('updated_at'),
                    default => $query,
                },
            )
            ->orderBy('id');
    }

    /**
     * Facilities open at the given time in Japan (a time without an offset
     * is read as Japan's): one of their opening periods covers it, on that
     * weekday or, on a public holiday, on the holiday ranges, and in that
     * week of the month.
     *
     * Fetching a page checks the facilities in order and stops once the
     * page is full (NO_SEMIJOIN). Left to itself, MySQL first collects
     * every facility open then (over 150,000 nationwide) and sorts them,
     * which took ~0.6s in production for any page. Counting visits far
     * more of them than a page does, and there collecting them first is
     * the faster way (~0.07s vs ~0.7s locally for all), so it gets no hint.
     *
     * @param  Builder<MedicalFacility>  $query
     * @return Builder<MedicalFacility>
     */
    private function applyOpenAt(Builder $query, string $value, bool $forCounting): Builder
    {
        $at = Carbon::parse($value, 'Asia/Tokyo')->setTimezone('Asia/Tokyo');
        $day = PublicHoliday::isHoliday($at) ? OpeningPeriods::HOLIDAY : $at->isoWeekday();
        $time = $at->format('H:i:s');

        return $query->whereExists(fn ($periods) => $periods
            ->selectRaw($forCounting ? '1' : '/*+ NO_SEMIJOIN() */ 1')
            ->from('medical_facility_opening_periods')
            ->whereColumn('medical_facility_opening_periods.medical_facility_id', 'medical_facilities.id')
            ->where('day', $day)
            ->where('opens', '<=', $time)
            ->where('closes', '>', $time)
            ->whereRaw('weeks & ? <> 0', [1 << intdiv($at->day - 1, 7)]));
    }

    /**
     * Each space-separated word (half- or full-width space) must appear in
     * the name or the address, so "札幌 眼科" finds eye clinics in Sapporo.
     *
     * @param  Builder<MedicalFacility>  $query
     * @return Builder<MedicalFacility>
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        foreach (MedicalFacilityIndexRequest::searchWords($term) as $word) {
            // Escape after normalizing, not before: NFKC turns full-width
            // "％＿＼" into the LIKE metacharacters "%_\" themselves.
            $namePattern = '%'.$this->escapeLike($this->itaijiNormalizer->normalize($word)).'%';
            $addressPattern = '%'.$this->escapeLike($this->addressNormalizer->normalize($word)).'%';

            $query->where(function ($query) use ($namePattern, $addressPattern): void {
                $query->where('name_normalized', 'like', $namePattern)
                    ->orWhere('address_normalized', 'like', $addressPattern);
            });
        }

        return $query;
    }

    /**
     * Facilities within $radius meters, with their distance as `distance`.
     * A latitude/longitude range narrows the rows first (using the
     * (latitude, longitude) index), then ST_Distance_Sphere measures the
     * real distance.
     *
     * @param  Builder<MedicalFacility>  $query
     * @return Builder<MedicalFacility>
     */
    private function applyNearby(Builder $query, float $latitude, float $longitude, int $radius, bool $orderByDistance): Builder
    {
        $latitudeDelta = $radius / self::METERS_PER_DEGREE;
        $longitudeDelta = $radius / (self::METERS_PER_DEGREE * cos(deg2rad($latitude)));
        $distance = 'ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?))';

        return $query
            ->select('medical_facilities.*')
            ->selectRaw("{$distance} AS distance", [$longitude, $latitude])
            ->whereBetween('latitude', [$latitude - $latitudeDelta, $latitude + $latitudeDelta])
            ->whereBetween('longitude', [$longitude - $longitudeDelta, $longitude + $longitudeDelta])
            ->whereRaw("{$distance} <= ?", [$longitude, $latitude, $radius])
            ->when($orderByDistance, fn (Builder $query) => $query->orderBy('distance'));
    }

    /**
     * Counts the matching rows, but no more than $limit: MySQL stops reading
     * once it has found that many, instead of reading every match. The
     * nearby search's distance column and ordering are dropped first, as
     * the count needs neither.
     *
     * @param  Builder<MedicalFacility>  $query
     */
    private function countUpTo(Builder $query, int $limit): int
    {
        $rows = $query->clone()->reorder()->select($query->qualifyColumn('id'))->limit($limit);

        return DB::query()->fromSub($rows, 'counted_rows')->count();
    }

    /**
     * Whether counting stopped at the cap, so the real total is larger.
     */
    private function isCapped(int $total, ?int $totalCap): bool
    {
        return $totalCap !== null && $total >= $totalCap;
    }

    /**
     * Makes user input match literally inside a LIKE pattern. Backslash
     * must be escaped first, since it is MySQL's default LIKE escape
     * character and the other replacements introduce new backslashes.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
