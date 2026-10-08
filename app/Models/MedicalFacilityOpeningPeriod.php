<?php

namespace App\Models;

use Database\Factories\MedicalFacilityOpeningPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day and time range a facility is open in (App\Services\MedicalInfoNet\OpeningPeriods).
 */
#[Fillable(['medical_facility_id', 'day', 'opens', 'closes', 'weeks'])]
class MedicalFacilityOpeningPeriod extends Model
{
    /** @use HasFactory<MedicalFacilityOpeningPeriodFactory> */
    use HasFactory;

    /**
     * Rebuilt as a whole, never edited, so no timestamps.
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'weeks' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MedicalFacility, $this>
     */
    public function medicalFacility(): BelongsTo
    {
        return $this->belongsTo(MedicalFacility::class);
    }
}
