<?php

namespace App\Console\Commands;

use App\Enums\GeocodeLevel;
use App\Models\MedicalFacility;
use App\Services\NationalLand\NationalLandImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Imports the 国土数値情報「医療機関」 positions. The data (2020年度) is not
 * updated, so this is run once rather than scheduled; running it again
 * simply replaces them. Facilities whose location could use them (町丁目,
 * 国土数値情報 or none) are then marked for facilities:geocode to locate
 * again on its next run; updated_at moves only for those whose location
 * actually changes.
 */
#[Signature('national-land:import')]
#[Description('Import facility positions from the MLIT 国土数値情報「医療機関」 data')]
class ImportNationalLandLocations extends Command
{
    public function handle(NationalLandImporter $importer): int
    {
        $result = $importer->import();

        $marked = MedicalFacility::query()
            ->where(fn ($query) => $query
                ->whereIn('geocode_level', [GeocodeLevel::Town, GeocodeLevel::NationalLand])
                ->orWhere(fn ($query) => $query->whereNull('geocode_level')->whereNotNull('geocoded_address')))
            ->toBase()
            ->update(['geocoded_address' => null]);

        $this->components->info("{$result['imported']}件を取り込みました（市区町村を特定できないなどで{$result['skipped']}件を除く）。{$marked}件の施設を、次回の facilities:geocode で付け直します。");

        return Command::SUCCESS;
    }
}
