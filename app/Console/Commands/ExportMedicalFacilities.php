<?php

namespace App\Console\Commands;

use App\Services\Export\FacilityExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rhb:export
    {--force : データが変わっていなくても作り直す}')]
#[Description('Write the in-scope facilities to per-prefecture and whole-scope CSV / JSON Lines files for bulk download')]
class ExportMedicalFacilities extends Command
{
    public function handle(FacilityExporter $exporter): int
    {
        $manifest = $exporter->export(force: (bool) $this->option('force'));

        if ($manifest === null) {
            $this->components->info('前回の作成からデータが変わっていないため、作り直しませんでした（--force で作り直せます）。');

            return Command::SUCCESS;
        }

        foreach ($manifest['files'] as $file) {
            $this->components->twoColumnDetail($file['name'], number_format($file['records']).'件 / '.number_format($file['size']).' bytes');
        }

        $this->components->info(count($manifest['files']).'個のファイルを作成しました。');

        return Command::SUCCESS;
    }
}
