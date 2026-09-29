<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MedicalFacilityEventOrigin;
use App\Enums\MedicalFacilityEventType;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityEventIndexRequest;
use App\Http\Resources\Api\V1\MedicalFacilityEventResource;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MedicalFacilityEventController extends Controller
{
    use ProvidesAttribution;

    /**
     * 変化の一覧
     *
     * 毎月の公開データを比較して見つかった、施設の新規・廃止・変更を新しい順に返します。
     * `occurred_on` は変化が載った公開データの日付で、実際の開業日・廃止日ではありません。
     * 取込を始めた時点のデータ（初回取込）と、取り込み直しによる差分（再処理）は含みません。
     */
    public function index(MedicalFacilityEventIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $events = $this->publicEvents()
            ->where('origin', MedicalFacilityEventOrigin::Detected)
            ->when($filters['event_type'] ?? null, fn ($query, $value) => $query->where('event_type', $value))
            ->when($filters['occurred_from'] ?? null, fn ($query, $date) => $query->where('occurred_on', '>=', $date))
            ->when($filters['occurred_to'] ?? null, fn ($query, $date) => $query->where('occurred_on', '<=', $date))
            ->when(
                isset($filters['prefecture_code']) || isset($filters['institution_type']),
                fn ($query) => $query->whereHas('medicalFacility', fn ($facilityQuery) => $facilityQuery
                    ->when($filters['prefecture_code'] ?? null, fn ($facilityQuery, $value) => $facilityQuery->where('prefecture_code', $value))
                    ->when($filters['institution_type'] ?? null, fn ($facilityQuery, $value) => $facilityQuery->where('institution_type', $value))),
            )
            ->with('medicalFacility')
            ->paginate($filters['per_page'] ?? 25);

        return MedicalFacilityEventResource::collection($events)
            ->additional(['meta' => ['attribution' => $this->attribution()]]);
    }

    /**
     * 施設の履歴
     *
     * 1つの施設の新規・廃止・変更を新しい順に返します。取込を始めた時点で掲載されていた施設は、
     * 最も古い記録が `origin` = 初回取込 の「新規」になります（開業日ではありません）。
     */
    public function facility(MedicalFacility $medicalFacility): AnonymousResourceCollection
    {
        $events = $this->publicEvents()
            ->whereBelongsTo($medicalFacility)
            ->where('origin', '!=', MedicalFacilityEventOrigin::Reprocessed)
            ->get();

        return MedicalFacilityEventResource::collection($events)
            ->additional(['meta' => ['attribution' => $this->attribution()]]);
    }

    /**
     * Events as served: newest first, flagged when a Created event follows an
     * earlier real closure, and without Updated events whose only changes are
     * personal names (they would show up as changes with nothing in them).
     *
     * @return Builder<MedicalFacilityEvent>
     */
    private function publicEvents(): Builder
    {
        // One placeholder per MedicalFacilityEventResource::HIDDEN_ATTRIBUTES entry.
        $hiddenPaths = array_map(
            fn (string $attribute): string => "$.{$attribute}",
            MedicalFacilityEventResource::HIDDEN_ATTRIBUTES,
        );

        return MedicalFacilityEvent::query()
            ->select('medical_facility_events.*')
            ->selectRaw(
                'exists (select 1 from medical_facility_events as earlier'
                .' where earlier.medical_facility_id = medical_facility_events.medical_facility_id'
                .' and earlier.event_type = ? and earlier.origin <> ?'
                .' and earlier.id < medical_facility_events.id) as is_reopening',
                [MedicalFacilityEventType::Removed->value, MedicalFacilityEventOrigin::Reprocessed->value],
            )
            ->where(fn ($query) => $query
                ->where('event_type', '!=', MedicalFacilityEventType::Updated)
                ->orWhereRaw('json_length(json_remove(payload, ?, ?)) > 0', $hiddenPaths))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id');
    }
}
