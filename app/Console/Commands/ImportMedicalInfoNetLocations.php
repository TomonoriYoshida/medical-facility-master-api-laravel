<?php

namespace App\Console\Commands;

use App\Enums\GeocodeLevel;
use App\Models\MedicalFacility;
use App\Services\MedicalInfoNet\MedicalInfoNetImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Imports the latest 医療情報ネット coordinates (published each June and
 * December), skipping a publication already imported. Facilities whose
 * location could use them (町丁目-level, 医療情報ネット or none) are then
 * marked for facilities:geocode to locate again on its next run; updated_at
 * moves only for those whose location actually changes.
 */
#[Signature('medical-info-net:import
    {--force : 取り込み済みの版でも取り込み直す}')]
#[Description('Import facility coordinates from the MHLW 医療情報ネット open data')]
class ImportMedicalInfoNetLocations extends Command
{
    public function handle(MedicalInfoNetImporter $importer): int
    {
        $result = $importer->import(force: (bool) $this->option('force'));

        if ($result === null) {
            $this->components->info('最新の版は取り込み済みです（--force で取り込み直せます）。');

            return Command::SUCCESS;
        }

        $marked = MedicalFacility::query()
            ->where(fn ($query) => $query
                ->whereIn('geocode_level', [GeocodeLevel::Town, GeocodeLevel::MedicalInfoNet])
                ->orWhere(fn ($query) => $query->whereNull('geocode_level')->whereNotNull('geocoded_address')))
            ->toBase()
            ->update(['geocoded_address' => null]);

        $this->components->info("{$result['published_on']->toDateString()}時点の版から{$result['imported']}件（うち座標あり{$result['located']}件）を取り込みました。{$marked}件の施設を、次回の facilities:geocode で付け直します。");

        return Command::SUCCESS;
    }
}
