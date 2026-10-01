<?php

use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\MedicalFacilityController;
use App\Http\Controllers\Api\V1\MedicalFacilityEventController;
use App\Http\Controllers\Api\V1\MedicalFacilityStatsController;
use App\Http\Controllers\Api\V1\OptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::apiResource('medical-facilities', MedicalFacilityController::class)->only(['index', 'show']);
    Route::get('medical-facilities/{medicalFacility}/events', [MedicalFacilityEventController::class, 'facility'])
        ->name('medical-facilities.events.index');
    Route::get('medical-facility-events', [MedicalFacilityEventController::class, 'index'])
        ->name('medical-facility-events.index');
    Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
    Route::get('exports/{filename}', [ExportController::class, 'show'])
        ->where('filename', '[A-Za-z0-9._-]+')
        ->middleware('throttle:exports')
        ->name('exports.show');
    // Aggregates of data that changes once a day; the controller also caches them.
    Route::get('stats/facilities', MedicalFacilityStatsController::class)
        ->middleware('cache.headers:public;max_age=3600;etag')
        ->name('stats.facilities');
    // Fixed values that change only with a deploy, so clients may cache them.
    Route::get('options', OptionController::class)
        ->middleware('cache.headers:public;max_age=86400;etag')
        ->name('options');
});
