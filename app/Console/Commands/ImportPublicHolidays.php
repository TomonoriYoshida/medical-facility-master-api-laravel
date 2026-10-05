<?php

namespace App\Console\Commands;

use App\Services\Holiday\PublicHolidayImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Imports Japan's public holidays from the Cabinet Office's list, which
 * gains the next year's holidays around February.
 */
#[Signature('holidays:import')]
#[Description('Import Japan\'s public holidays from the Cabinet Office (内閣府)')]
class ImportPublicHolidays extends Command
{
    public function handle(PublicHolidayImporter $importer): int
    {
        $result = $importer->import();

        $this->components->info("祝日を{$result['imported']}日分（{$result['until']->toDateString()}まで）取り込みました。");

        return Command::SUCCESS;
    }
}
