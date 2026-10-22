<?php

namespace App\Console\Commands;

use App\Services\EPrescription\EPrescriptionImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sets each facility's 電子処方箋 start dates from the MHLW's list (updated
 * weekly), skipping a list already imported.
 */
#[Signature('e-prescriptions:import
    {--force : 取り込み済みの版でも取り込み直す}')]
#[Description('Import which facilities use 電子処方箋 from the MHLW\'s list (厚生労働省)')]
class ImportEPrescriptions extends Command
{
    public function handle(EPrescriptionImporter $importer): int
    {
        // The list's ~460,000 shared strings and the dates of ~92,500
        // facilities peaked at 96-128 MB in October 2026, near PHP's
        // default limit, and the list keeps growing.
        ini_set('memory_limit', '256M');

        $result = $importer->import(force: (bool) $this->option('force'));

        if ($result === null) {
            $this->components->info('最新の版は取り込み済みです（--force で取り込み直せます）。');

            return Command::SUCCESS;
        }

        $this->components->info("{$result['as_of']->toDateString()}時点の電子処方箋の対応施設を取り込みました（一覧{$result['listed']}件のうち{$result['matched']}件が一致、{$result['changed']}件を更新）。");

        return Command::SUCCESS;
    }
}
