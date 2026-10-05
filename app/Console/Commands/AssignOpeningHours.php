<?php

namespace App\Console\Commands;

use App\Services\MedicalInfoNet\OpeningHoursAssigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Matches the facilities with the 医療情報ネット and stores when each is
 * open (OpeningHoursAssigner), for the opening hours endpoint and the list
 * API's open_at. Skipped when neither the facilities nor the 医療情報ネット
 * changed since the last run.
 */
#[Signature('facilities:assign-opening-hours
    {--force : 施設・医療情報ネットに変化がなくても作り直す}')]
#[Description('Match facilities with the 医療情報ネット and store when they are open')]
class AssignOpeningHours extends Command
{
    public function handle(OpeningHoursAssigner $assigner): int
    {
        $result = $assigner->assign(force: (bool) $this->option('force'));

        if ($result === null) {
            $this->components->info('施設・医療情報ネットとも前回から変わっていません（--force で作り直せます）。');

            return Command::SUCCESS;
        }

        $this->components->info("{$result['facilities']}施設のうち{$result['matched']}施設を医療情報ネットと照合し、診療時間を{$result['periods']}件の時間帯として保存しました。");

        return Command::SUCCESS;
    }
}
