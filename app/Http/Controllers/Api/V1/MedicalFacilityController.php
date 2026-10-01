<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersMedicalFacilities;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityIndexRequest;
use App\Http\Resources\Api\V1\MedicalFacilityResource;
use App\Models\MedicalFacility;
use App\Services\Text\AddressNormalizer;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
     */
    public function index(MedicalFacilityIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $facilities = $this->applyFilters(MedicalFacility::query(), $filters)
            ->when($filters['q'] ?? null, fn ($query, $term) => $this->applySearch($query, $term))
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
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return MedicalFacilityResource::collection($facilities)
            ->additional(['meta' => ['attribution' => $this->attribution()]]);
    }

    /**
     * 施設詳細
     *
     * idを指定して医療施設マスタの1件を返します。
     */
    public function show(MedicalFacility $medicalFacility): MedicalFacilityResource
    {
        return (new MedicalFacilityResource($medicalFacility))
            ->additional(['meta' => ['attribution' => $this->attribution()]]);
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
     * Makes user input match literally inside a LIKE pattern. Backslash
     * must be escaped first, since it is MySQL's default LIKE escape
     * character and the other replacements introduce new backslashes.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
