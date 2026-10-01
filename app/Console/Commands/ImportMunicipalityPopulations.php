<?php

namespace App\Console\Commands;

use App\Services\Population\MunicipalityPopulationImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Imports the latest 住民基本台帳人口 by municipality (published yearly, as
 * of January 1), skipping an edition already imported.
 */
#[Signature('population:import
    {--force : 取り込み済みの版でも取り込み直す}')]
#[Description('Import the population of each municipality from 総務省 住民基本台帳人口')]
class ImportMunicipalityPopulations extends Command
{
    public function handle(MunicipalityPopulationImporter $importer): int
    {
        $result = $importer->import(force: (bool) $this->option('force'));

        if ($result === null) {
            $this->components->info('最新の版は取り込み済みです（--force で取り込み直せます）。');

            return Command::SUCCESS;
        }

        $this->components->info("{$result['as_of']->toDateString()}時点の人口を{$result['imported']}市区町村分取り込みました。");

        return Command::SUCCESS;
    }
}
