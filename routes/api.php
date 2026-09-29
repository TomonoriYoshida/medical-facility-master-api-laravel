<?php

use App\Http\Controllers\Api\V1\MedicalFacilityController;
use App\Http\Controllers\Api\V1\MedicalFacilityEventController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::apiResource('medical-facilities', MedicalFacilityController::class)->only(['index', 'show']);
    Route::get('medical-facilities/{medicalFacility}/events', [MedicalFacilityEventController::class, 'facility'])
        ->name('medical-facilities.events.index');
    Route::get('medical-facility-events', [MedicalFacilityEventController::class, 'index'])
        ->name('medical-facility-events.index');
});
