<?php

namespace App\Providers;

use App\Models\MedicalFacility;
use App\Models\User;
use App\Observers\MedicalFacilityObserver;
use App\Services\Address\MunicipalityResolver;
use App\Services\OpenApi\DescribesEnumCases;
use App\Services\OpenApi\LocalizesDescriptions;
use App\Services\Text\ItaijiNormalizer;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ItaijiNormalizer::class);
        $this->app->singleton(MunicipalityResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        MedicalFacility::observe(MedicalFacilityObserver::class);

        // The API itself is public and unauthenticated (public open data),
        // so its Scramble-generated docs are public too, not just in local.
        // The nullable $user is what lets the gate run for guests at all:
        // without it, Laravel denies every unauthenticated request.
        Gate::define('viewApiDocs', fn (?User $user): bool => true);

        Scramble::configure()->withDocumentTransformers([
            DescribesEnumCases::class,
            LocalizesDescriptions::class,
        ]);

        // A list that reports meta.max_page (LimitsPageDepth) must not link
        // past it: links.next stops at the last allowed page, and links.last
        // points there instead of at the true last page.
        AnonymousResourceCollection::macro('paginationInformation', function (Request $request, array $paginated, array $default): array {
            /** @var AnonymousResourceCollection $this */
            $maxPage = $this->additional['meta']['max_page'] ?? null;

            if (! is_int($maxPage) || ! $this->resource instanceof LengthAwarePaginator) {
                return $default;
            }

            if ($this->resource->currentPage() >= $maxPage) {
                $default['links']['next'] = null;
            }

            if ($this->resource->lastPage() > $maxPage) {
                $default['links']['last'] = $this->resource->url($maxPage);
            }

            return $default;
        });

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(config('api.rate_limit_per_minute'))
            ->by($request->ip()));

        RateLimiter::for('docs', fn (Request $request): Limit => Limit::perMinute(config('api.docs_rate_limit_per_minute'))
            ->by($request->ip()));

        RateLimiter::for('exports', fn (Request $request): Limit => Limit::perHour(config('api.export_downloads_per_hour'))
            ->by($request->ip()));
    }
}
