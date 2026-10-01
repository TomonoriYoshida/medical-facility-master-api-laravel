<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventOrigin;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\MedicalFacilityEvent;
use Dedoc\Scramble\Attributes\SchemaVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Updated event's payload holds each changed attribute's raw stored
 * values ({attribute: {old, new}}): enums as their backed values, dates as
 * either Y-m-d strings or serialized Carbon timestamps. They are converted
 * to the same names and shapes MedicalFacilityResource uses, so a change
 * reads like the facility itself.
 *
 * The event feed loads each event's facility and always returns it; a
 * facility's own history does not. The schema variants document both, so
 * the feed's `facility` is required rather than an anonymous overlay that
 * client generators read as an empty object.
 *
 * @mixin MedicalFacilityEvent
 */
#[SchemaVariant('MedicalFacilityEventResource', default: true)]
#[SchemaVariant('MedicalFacilityEventWithFacilityResource', whenLoaded: ['medicalFacility'])]
class MedicalFacilityEventResource extends JsonResource
{
    /**
     * Personal names are kept in the database but never served (PR #52,
     * DATABASE.md), including as changes.
     */
    public const array HIDDEN_ATTRIBUTES = ['founder_name', 'administrator_name'];

    /**
     * Changes are listed in MedicalFacilityResource's field order: MySQL
     * stores JSON object keys in its own order, so the payload's is lost.
     */
    private const array ATTRIBUTE_ORDER = [
        'facility_code', 'institution_type', 'status', 'bureau_code', 'name',
        'prefecture_code', 'postal_code', 'address', 'phone_number',
        'designated_on', 'designation_history', 'bed_counts', 'department_categories',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->codeAndLabel($this->event_type),
            'origin' => $this->codeAndLabel($this->origin),
            /** 変化が載った公開データの日付（実際の開業・廃止・変更の日ではない） */
            'occurred_on' => $this->occurred_on->toDateString(),
            /** 過去に廃止された施設が再び掲載されたか（新規のときのみ） */
            'is_reopening' => $this->when(
                $this->event_type === MedicalFacilityEventType::Created,
                // Selected by MedicalFacilityEventController, not a column.
                fn (): bool => (bool) $this->resource->getAttribute('is_reopening'),
            ),
            /** 変更された項目と変更前後の値（変更のときのみ） */
            'changes' => $this->when(
                $this->event_type === MedicalFacilityEventType::Updated,
                fn (): array => $this->changes(),
            ),
            'facility' => new MedicalFacilityResource($this->whenLoaded('medicalFacility')),
        ];
    }

    /**
     * @return list<array{attribute: string, old: mixed, new: mixed}>
     */
    private function changes(): array
    {
        $payload = $this->payload ?? [];

        uksort($payload, fn (string $a, string $b): int => $this->attributePosition($a) <=> $this->attributePosition($b));

        $changes = [];

        foreach ($payload as $attribute => $change) {
            if (in_array($attribute, self::HIDDEN_ATTRIBUTES, true)) {
                continue;
            }

            $changes[] = [
                'attribute' => $attribute === 'bureau_code' ? 'bureau' : $attribute,
                'old' => $this->formatValue($attribute, $change['old'] ?? null),
                'new' => $this->formatValue($attribute, $change['new'] ?? null),
            ];
        }

        return $changes;
    }

    private function attributePosition(string $attribute): int
    {
        $position = array_search($attribute, self::ATTRIBUTE_ORDER, true);

        return $position === false ? count(self::ATTRIBUTE_ORDER) : $position;
    }

    private function formatValue(string $attribute, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($attribute) {
            'institution_type' => $this->codeAndLabel(InstitutionType::from($value)),
            'status' => $this->codeAndLabel(MedicalFacilityStatus::from($value)),
            'bureau_code' => $this->codeAndLabel(RhbBureau::from($value)),
            'department_categories' => array_map(
                fn (int $category): array => $this->codeAndLabel(DepartmentBaseCategory::from($category)),
                $value,
            ),
            // The app runs in UTC, so a serialized Carbon's date part is the date.
            'designated_on' => substr($value, 0, 10),
            default => $value,
        };
    }

    /**
     * @return array{code: int, label: string}
     */
    private function codeAndLabel(
        InstitutionType|MedicalFacilityStatus|RhbBureau|DepartmentBaseCategory|MedicalFacilityEventType|MedicalFacilityEventOrigin $enum,
    ): array {
        return [
            'code' => $enum->value,
            'label' => $enum->label(),
        ];
    }
}
