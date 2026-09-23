<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('rhb:download')
    ->dailyAt('05:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();

// rhb:import only enqueues jobs onto QUEUE_CONNECTION; a separately-running
// worker (queue:work, or the queue:listen process `composer run dev`
// starts locally) must be running for the enqueued jobs to actually
// process -- see ImportRhbFacilityListJob's docblock. Real downloads
// across all 8 bureaus complete in a few minutes, so 30 minutes leaves
// ample buffer before import runs.
Schedule::command('rhb:import')
    ->dailyAt('05:30')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();
