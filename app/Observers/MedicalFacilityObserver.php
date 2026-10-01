<?php

namespace App\Observers;

use App\Models\MedicalFacility;
use App\Services\Address\MunicipalityResolver;
use App\Services\Text\AddressNormalizer;
use App\Services\Text\ItaijiNormalizer;

class MedicalFacilityObserver
{
    public function __construct(
        private readonly ItaijiNormalizer $normalizer,
        private readonly AddressNormalizer $addressNormalizer,
        private readonly MunicipalityResolver $municipalityResolver,
    ) {}

    /**
     * Keep name_normalized/address_normalized/municipality_code in sync
     * whenever their source columns change.
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

        if ($medicalFacility->isDirty('address')) {
            $medicalFacility->address_normalized = $this->addressNormalizer->normalize($medicalFacility->address);
        }

        if ($medicalFacility->isDirty(['prefecture_code', 'address'])) {
            $medicalFacility->municipality_code = $this->municipalityResolver->resolve($medicalFacility->prefecture_code, $medicalFacility->address);
        }
    }
}
