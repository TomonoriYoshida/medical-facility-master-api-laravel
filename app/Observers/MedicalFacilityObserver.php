<?php

namespace App\Observers;

use App\Models\MedicalFacility;
use App\Services\Text\ItaijiNormalizer;

class MedicalFacilityObserver
{
    public function __construct(private readonly ItaijiNormalizer $normalizer) {}

    /**
     * Keep name_normalized/short_name_normalized in sync whenever the
     * source columns change.
     *
     * Note: DatabaseSeeder uses WithoutModelEvents, so any future bulk
     * importer that reuses that trait (or otherwise bypasses Eloquent
     * events, e.g. DB::table()->insert()) must normalize explicitly --
     * this observer will not run for it.
     */
    public function saving(MedicalFacility $medicalFacility): void
    {
        if ($medicalFacility->isDirty('name')) {
            $medicalFacility->name_normalized = $this->normalizer->normalize($medicalFacility->name);
        }

        if ($medicalFacility->isDirty('short_name') && $medicalFacility->short_name !== null) {
            $medicalFacility->short_name_normalized = $this->normalizer->normalize($medicalFacility->short_name);
        }
    }
}
