<?php

use App\Http\Controllers\Api\V1\MedicalFacilityController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::apiResource('medical-facilities', MedicalFacilityController::class)->only(['index', 'show']);
});
