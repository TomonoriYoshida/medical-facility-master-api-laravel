<?php

namespace App\Console\Commands;

use App\Services\NationalLand\RailwayStationImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Imports the stations of the 国土数値情報「鉄道」 for the station search.
 * The data is published once a year, so this is run by hand after pointing
 * config('national_land.stations') at a new edition; running it again
 * simply replaces them. Run it after facilities:geocode, as each station's
 * prefecture and municipality are taken from the facilities around it.
 */
#[Signature('stations:import')]
#[Description('Import railway stations from the MLIT 国土数値情報「鉄道」 data')]
class ImportRailwayStations extends Command
{
    public function handle(RailwayStationImporter $importer): int
    {
        $result = $importer->import();

        $this->components->info("{$result['imported']}駅を取り込みました（うち{$result['located']}駅は、近くの施設から都道府県・市区町村を付けました）。");

        if ($result['skipped'] > 0) {
            $this->components->warn("駅コード・駅名・ホームの線（LineString）のいずれかがない{$result['skipped']}件を除きました。データの形が変わっていないか確かめてください。");
        }

        return Command::SUCCESS;
    }
}
