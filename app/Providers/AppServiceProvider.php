<?php

namespace App\Providers;

use App\Models\MedicalFacility;
use App\Observers\MedicalFacilityObserver;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ItaijiNormalizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        MedicalFacility::observe(MedicalFacilityObserver::class);
    }
}
