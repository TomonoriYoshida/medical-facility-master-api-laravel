<?php

use Illuminate\Support\Facades\Schedule;

$downloadHealthcheckUrl = config('rhb.healthchecks.download_url');
$statusHealthcheckUrl = config('rhb.healthchecks.status_url');

Schedule::command('rhb:download')
    ->dailyAt('05:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->pingOnSuccessIf(filled($downloadHealthcheckUrl), (string) $downloadHealthcheckUrl)
    ->pingOnFailureIf(filled($downloadHealthcheckUrl), "{$downloadHealthcheckUrl}/fail");

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

// The 医療情報ネット coordinates are published each June and December; a
// publication already imported is skipped, so checking monthly is enough.
Schedule::command('medical-info-net:import')
    ->monthlyOn(2, '04:30')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();

// The 住民基本台帳人口 (as of January 1) is published once a year, around
// summer; an edition already imported is skipped, so checking monthly is enough.
Schedule::command('population:import')
    ->monthlyOn(3, '04:40')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();

// Locates new and moved facilities; after the import (05:30, ~20 minutes)
// and before rhb:export, so the day's files carry the locations.
Schedule::command('facilities:geocode')
    ->dailyAt('06:30')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();

// Bulk download files, rebuilt only when the imported data changed.
Schedule::command('rhb:export')
    ->dailyAt('07:10')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();

// Since rhb:import's exit code cannot reflect the queued jobs' outcome,
// rhb:status reports whether they succeeded. A full import takes ~20
// minutes, so by 07:00 every job has finished (or failed for good).
Schedule::command('rhb:status')
    ->dailyAt('07:00')
    ->timezone('Asia/Tokyo')
    ->pingOnSuccessIf(filled($statusHealthcheckUrl), (string) $statusHealthcheckUrl)
    ->pingOnFailureIf(filled($statusHealthcheckUrl), "{$statusHealthcheckUrl}/fail");

// Yesterday's access log, read once the day is over; alerts through the log
// when the traffic looks like an attack (see config/api.php).
Schedule::command('access-log:check')
    ->dailyAt('00:15')
    ->timezone('Asia/Tokyo');
