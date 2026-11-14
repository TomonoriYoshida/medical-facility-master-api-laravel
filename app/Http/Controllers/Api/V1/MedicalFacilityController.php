<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\EscapesLikePatterns;
use App\Http\Controllers\Api\V1\Concerns\FiltersMedicalFacilities;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityIndexRequest;
use App\Http\Resources\Api\V1\MedicalFacilityResource;
use App\Models\MedicalFacility;
use App\Services\MedicalInfoNet\ArrivalHours;
use App\Services\MedicalInfoNet\OpeningMoment;
use App\Services\MedicalInfoNet\TravelTime;
use App\Services\Text\AddressNormalizer;
use App\Services\Text\ItaijiNormalizer;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MedicalFacilityController extends Controller
{
    use EscapesLikePatterns;
    use FiltersMedicalFacilities;
    use ProvidesAttribution;

    private const int DEFAULT_RADIUS = 1000;

    /** How long a `q` search's total is reused; see rememberSearchTotal(). */
    private const int SEARCH_TOTAL_CACHE_SECONDS = 3600;

    /** Meters per degree of latitude (and of longitude at the equator). */
    private const float METERS_PER_DEGREE = 111_320;

    public function __construct(
        private readonly ItaijiNormalizer $itaijiNormalizer,
        private readonly AddressNormalizer $addressNormalizer,
        private readonly ArrivalHours $arrivalHours,
    ) {}

    /**
     * 施設一覧・検索
     *
     * 医療施設マスタをページネーション付きで返します。`q` は施設名・住所の全角半角/異体字ゆれを
     * 吸収したあいまい検索です。`latitude`・`longitude` を指定すると、`radius` 以内の施設を近い順に返します。
     * `open_at` を指定すると、その日時に受付中の施設（厚生労働省「医療情報ネット」の診療時間で判定）だけを返し、
     * 各施設に受付が終わる日時 `open_until` を含めます。
     * さらに `travel`（`walk` / `car`）を指定すると、`open_at` を出発時刻として、施設ごとに直線距離から見積もった到着の目安の時刻で判定し、
     * `wait` を指定すると、到着からその分数以内に受付が始まる施設も含めます（到着の目安 `arrives_at`、受付が始まる日時 `opens_at` が付きます）。
     */
    public function index(MedicalFacilityIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $openAt = isset($filters['open_at']) ? $this->openAt((string) $filters['open_at']) : null;
        $facilities = $this->facilities($filters, $openAt, forCounting: false);

        // withQueryString(): the links.next a client follows must carry the
        // same filters (and, for a cursor, pagination=cursor itself).
        if ($request->usesCursor()) {
            $cursorPaginator = $facilities->cursorPaginate($request->perPage())->withQueryString();
            $this->addClosingTimes($cursorPaginator->getCollection(), $openAt, $filters);

            return MedicalFacilityResource::collection($cursorPaginator)
                ->additional(['meta' => ['attribution' => $this->attribution()]]);
        }

        $totalCap = $request->totalCap();
        $paginator = $facilities->paginate($request->perPage(), total: fn (): int => $this->rememberSearchTotal($filters, function () use ($filters, $openAt, $totalCap): int {
            $counted = $this->facilities($filters, $openAt, forCounting: true);

            return $totalCap === null ? $counted->toBase()->getCountForPagination() : $this->countUpTo($counted, $totalCap);
        }));

        $this->addClosingTimes($paginator->getCollection(), $openAt, $filters);

        return MedicalFacilityResource::collection($paginator->withQueryString())
            ->additional(['meta' => [
                /**
                 * ページ番号で取得できる最後のページ（最初の1万件まで）
                 *
                 * @example 400
                 */
                'max_page' => $request->maxPage(),
                /**
                 * `total=capped` で件数を数え終えたか。true なら `total` は 10,001 で、実際の件数はそれ以上
                 *
                 * @example false
                 */
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
    private function facilities(array $filters, ?Carbon $openAt, bool $forCounting): Builder
    {
        return $this->applyFilters(MedicalFacility::query(), $filters)
            ->when($filters['q'] ?? null, fn ($query, $term) => $this->applySearch($query, $term))
            ->when($openAt, fn ($query, Carbon $at) => $this->applyOpenAt(
                $query,
                $at,
                $filters,
                checksInOrder: ! $forCounting && ! isset($filters['latitude'], $filters['longitude']),
            ))
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
     * The `open_at` time in Japan; a time without an offset is read as Japan's.
     */
    private function openAt(string $value): Carbon
    {
        return Carbon::parse($value, 'Asia/Tokyo')->setTimezone('Asia/Tokyo');
    }

    /**
     * Gives each facility on the page its closing time as `open_until`,
     * looked up for the whole page at once (ArrivalHours). Without `travel`
     * and `wait` that is the range open at $openAt; with them, the range
     * around each facility's arrival (see applyArrivalWindow()), with
     * `arrives_at` when travelling and `opens_at` when it opens only after
     * the arrival.
     *
     * @param  Collection<int, MedicalFacility>  $facilities
     * @param  array<string, mixed>  $filters
     */
    private function addClosingTimes(Collection $facilities, ?Carbon $openAt, array $filters): void
    {
        if ($openAt === null) {
            return;
        }

        $travel = $filters['travel'] ?? null;
        $wait = (int) ($filters['wait'] ?? 0);
        $arrivals = $facilities->mapWithKeys(fn (MedicalFacility $facility): array => [
            $facility->id => $travel === null
                ? $openAt->copy()
                : $openAt->copy()->addMinutes(TravelTime::minutes($travel, (float) $facility->getAttribute('distance'))),
        ])->all();
        $hours = $this->arrivalHours->at($arrivals, $wait);

        foreach ($facilities as $facility) {
            $facility->setAttribute('arrives_at', $travel === null ? null : $arrivals[$facility->id]);
            $facility->setAttribute('opens_at', $hours[$facility->id]['opens_at'] ?? null);
            $facility->setAttribute('open_until', $hours[$facility->id]['open_until'] ?? null);
        }
    }

    /**
     * Facilities open at the given time in Japan: one of their opening periods covers it, on that
     * weekday or, on a public holiday, on the holiday ranges, and in that
     * week of the month.
     *
     * Fetching a page checks the facilities in order and stops once the
     * page is full (NO_SEMIJOIN). Left to itself, MySQL first collects
     * every facility open then (over 150,000 nationwide) and sorts them,
     * which took ~0.6s in production for any page. Counting visits far
     * more of them than a page does, and there collecting them first is
     * the faster way (~0.07s vs ~0.7s locally for all), so it gets no hint.
     * Nor does a nearby search's page: every facility in the area has to be
     * checked to find the nearest anyway, and checking them one by one made
     * MySQL check every facility in Japan before the area (~0.23s vs ~0.03s
     * locally for 50km around Tokyo; ~5s in production).
     *
     * @param  Builder<MedicalFacility>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<MedicalFacility>
     */
    private function applyOpenAt(Builder $query, Carbon $at, array $filters, bool $checksInOrder): Builder
    {
        $travel = $filters['travel'] ?? null;
        $wait = (int) ($filters['wait'] ?? 0);

        if ($travel !== null || $wait > 0) {
            return $this->applyArrivalWindow($query, $at, $travel, $wait, $filters);
        }

        $moment = OpeningMoment::at($at);

        return $query->whereExists(fn ($periods) => $periods
            ->selectRaw($checksInOrder ? '/*+ NO_SEMIJOIN() */ 1' : '1')
            ->from('medical_facility_opening_periods')
            ->whereColumn('medical_facility_opening_periods.medical_facility_id', 'medical_facilities.id')
            ->where('day', $moment->day)
            ->where('opens', '<=', $moment->time)
            ->where('closes', '>', $moment->time)
            ->whereRaw('weeks & ? <> 0', [$moment->weekBit]));
    }

    /**
     * Facilities with opening hours between each one's arrival and $wait
     * minutes after it: open when reached, or opening shortly after. The
     * arrival is $at plus the travel time estimated from the facility's
     * straight-line distance (TravelTime), or $at itself without `travel`.
     *
     * Times are compared as seconds from the start of $at's day, so a range
     * on the next day counts 86,400 more. The longest trip, 50 km on foot
     * (the radius limit), is estimated at ~13.5 hours; with an hour's wait
     * that is still well under a day, so the next day is as far as any
     * arrival reaches. (A longer radius or slower speed would need a third day.)
     *
     * @param  Builder<MedicalFacility>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<MedicalFacility>
     */
    private function applyArrivalWindow(Builder $query, Carbon $at, ?string $travel, int $wait, array $filters): Builder
    {
        $at = $at->copy()->setTimezone('Asia/Tokyo');
        $today = OpeningMoment::at($at);
        $tomorrow = OpeningMoment::at($at->copy()->addDay()->startOfDay());

        $arrival = '?';
        $arrivalBindings = [$at->secondsSinceMidnight()];

        if ($travel !== null) {
            $distance = 'ST_Distance_Sphere(POINT(medical_facilities.longitude, medical_facilities.latitude), POINT(?, ?))';
            $arrival = '(? + 60 * '.TravelTime::minutesSql($travel, $distance).')';
            $arrivalBindings = [$at->secondsSinceMidnight(), (float) $filters['longitude'], (float) $filters['latitude']];
        }

        $onDay = fn (OpeningMoment $moment, int $offset) => fn ($periods) => $periods
            ->where('day', $moment->day)
            ->whereRaw('weeks & ? <> 0', [$moment->weekBit])
            ->whereRaw("TIME_TO_SEC(opens) + {$offset} <= {$arrival} + ?", [...$arrivalBindings, $wait * 60])
            ->whereRaw("TIME_TO_SEC(closes) + {$offset} > {$arrival}", $arrivalBindings);

        return $query->whereExists(fn ($periods) => $periods
            ->selectRaw('1')
            ->from('medical_facility_opening_periods')
            ->whereColumn('medical_facility_opening_periods.medical_facility_id', 'medical_facilities.id')
            ->where(fn ($periods) => $periods
                ->where($onDay($today, 0))
                ->orWhere($onDay($tomorrow, 86400))));
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
     * Caches the total of a `q` search for an hour, as the stats endpoints
     * do (RemembersStats). Its LIKE '%word%' reads every facility to count
     * the matches (~0.1s in production), and the same few words (歯科, 眼科,
     * 内科...) are searched again and again while paging, whereas the data
     * changes once a day (the import), so an hour of staleness is harmless.
     *
     * Only the filters that change the total make up the key, so paging or
     * re-sorting reuses it. Searches without `q` are not cached, as they are
     * mostly cheap. Nor is a nearby search, so that no searched location is
     * kept (#117).
     *
     * @param  array<string, mixed>  $filters
     * @param  Closure(): int  $count
     */
    private function rememberSearchTotal(array $filters, Closure $count): int
    {
        if (! isset($filters['q']) || isset($filters['latitude'])) {
            return $count();
        }

        $countedFilters = Arr::except($filters, ['page', 'per_page', 'sort', 'pagination', 'cursor']);
        ksort($countedFilters);

        return Cache::remember(
            'medical-facility-search-total:v1:'.sha1((string) json_encode($countedFilters)),
            self::SEARCH_TOTAL_CACHE_SECONDS,
            $count,
        );
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
}
