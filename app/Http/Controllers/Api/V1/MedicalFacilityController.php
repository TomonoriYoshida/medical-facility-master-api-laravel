<?php

namespace App\Http\Controllers\Api\V1;

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
            ->when($filters['prefecture_code'] ?? null, fn ($query, $value) => $query->where('prefecture_code', $value))
            ->when($filters['institution_type'] ?? null, fn ($query, $value) => $query->where('institution_type', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['bureau_code'] ?? null, fn ($query, $value) => $query->where('bureau_code', $value))
            ->when($filters['department_category'] ?? null, fn ($query, $value) => $query->whereJsonContains('department_categories', (int) $value))
            ->when($filters['q'] ?? null, fn ($query, $term) => $this->applySearch($query, $term))
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
        $normalizedName = $this->itaijiNormalizer->normalize($term);
        $normalizedAddress = $this->addressNormalizer->normalize($term);

        return $query->where(function ($query) use ($normalizedName, $normalizedAddress): void {
            $query->where('name_normalized', 'like', "%{$normalizedName}%")
                ->orWhere('address_normalized', 'like', "%{$normalizedAddress}%");
        });
    }

    /**
     * Source attribution required by the regional health bureaus' public
     * data terms (PDL1.0): DATABASE.md "データの出典・利用条件". Included on
     * every response regardless of which bureaus its facilities come from.
     *
     * @return array<string, mixed>
     */
    private function attribution(): array
    {
        return [
            'notice' => '本APIのデータは、各地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を加工して作成しています。',
            'sources' => collect(config('rhb.bureaus'))
                ->map(fn (array $bureau) => [
                    'bureau' => $bureau['label'],
                    'url' => $bureau['index_url'],
                ])
                ->values(),
        ];
    }
}
