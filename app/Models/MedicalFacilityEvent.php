<?php

namespace App\Models;

use App\Enums\MedicalFacilityEventType;
use Database\Factories\MedicalFacilityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medical_facility_id',
    'department_code',
    'event_type',
    'occurred_on',
    'payload',
    'mhlw_dataset_download_id',
])]
class MedicalFacilityEvent extends Model
{
    /** @use HasFactory<MedicalFacilityEventFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MedicalFacility, $this>
     */
    public function medicalFacility(): BelongsTo
    {
        return $this->belongsTo(MedicalFacility::class);
    }

    /**
     * @return BelongsTo<MhlwDatasetDownload, $this>
     */
    public function mhlwDatasetDownload(): BelongsTo
    {
        return $this->belongsTo(MhlwDatasetDownload::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => MedicalFacilityEventType::class,
            'occurred_on' => 'date',
            'payload' => 'array',
        ];
    }
}
