<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityIndexRequest;
use App\Http\Resources\Api\V1\MedicalFacilityResource;
use App\Models\MedicalFacility;
use App\Services\Text\AddressNormalizer;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class MedicalFacilityController extends Controller
{
    use ProvidesAttribution;

    public function __construct(
        private readonly ItaijiNormalizer $itaijiNormalizer,
        private readonly AddressNormalizer $addressNormalizer,
    ) {}

    /**
     * 施設一覧・検索
     *
     * 医療施設マスタをページネーション付きで返します。`q` は施設名・住所の全角半角/異体字ゆれを
     * 吸収したあいまい検索です。
     */
    public function index(MedicalFacilityIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $facilities = MedicalFacility::query()
            ->when($filters['medical_institution_code'] ?? null, fn ($query, $codes) => $query->whereIn('medical_institution_code', explode(',', $codes)))
            ->when($filters['prefecture_code'] ?? null, fn ($query, $value) => $query->where('prefecture_code', $value))
            ->when($filters['municipality_code'] ?? null, fn ($query, $value) => $query->where('municipality_code', $value))
            ->when($filters['institution_type'] ?? null, fn ($query, $value) => $query->where('institution_type', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['bureau_code'] ?? null, fn ($query, $value) => $query->where('bureau_code', $value))
            ->when($filters['department_category'] ?? null, fn ($query, $value) => $query->whereJsonContains('department_categories', (int) $value))
            ->when($filters['q'] ?? null, fn ($query, $term) => $this->applySearch($query, $term))
            ->when($filters['designated_from'] ?? null, fn ($query, $date) => $query->where('designated_on', '>=', $date))
            ->when($filters['designated_to'] ?? null, fn ($query, $date) => $query->where('designated_on', '<=', $date))
            // updated_at is stored in UTC; the given offset, if any, is honored.
            ->when($filters['updated_since'] ?? null, fn ($query, $since) => $query->where('updated_at', '>=', Carbon::parse($since)->utc()))
            ->when($filters['designation_reason'] ?? null, fn ($query, $reason) => $query->whereJsonContains('designation_history', ['reason' => $reason]))
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
     * @param  Builder<MedicalFacility>  $query
     * @return Builder<MedicalFacility>
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        // Escape after normalizing, not before: NFKC turns full-width
        // "％＿＼" into the LIKE metacharacters "%_\" themselves.
        $namePattern = '%'.$this->escapeLike($this->itaijiNormalizer->normalize($term)).'%';
        $addressPattern = '%'.$this->escapeLike($this->addressNormalizer->normalize($term)).'%';

        return $query->where(function ($query) use ($namePattern, $addressPattern): void {
            $query->where('name_normalized', 'like', $namePattern)
                ->orWhere('address_normalized', 'like', $addressPattern);
        });
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
