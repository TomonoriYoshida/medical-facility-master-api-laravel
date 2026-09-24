<?php

namespace App\Providers;

use App\Models\MedicalFacility;
use App\Observers\MedicalFacilityObserver;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Support\Facades\Gate;
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

        // The API itself is public and unauthenticated (public open data),
        // so its Scramble-generated docs are public too, not just in local.
        Gate::define('viewApiDocs', fn (): bool => true);
    }
}
