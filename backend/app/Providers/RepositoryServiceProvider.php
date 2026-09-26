<?php

namespace App\Providers;

use App\Repositories\Cache\CachedAlertSettingRepository;
use App\Repositories\Cache\CachedCatalogRepository;
use App\Repositories\Cache\CachedForecastQueryRepository;
use App\Repositories\Cache\CachedShopRepository;
use App\Repositories\Cache\CachedSupplierRepository;
use App\Repositories\Cache\CachedSyncRunRepository;
use App\Repositories\Cache\CachedVariantRepository;
use App\Repositories\Contracts\AlertLogRepositoryInterface;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\ForecastRepositoryInterface;
use App\Repositories\Contracts\LocationSalesRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Repositories\Eloquent\EloquentAlertLogRepository;
use App\Repositories\Eloquent\EloquentAlertSettingRepository;
use App\Repositories\Eloquent\EloquentCatalogRepository;
use App\Repositories\Eloquent\EloquentDailySalesRepository;
use App\Repositories\Eloquent\EloquentForecastQueryRepository;
use App\Repositories\Eloquent\EloquentForecastRepository;
use App\Repositories\Eloquent\EloquentLocationSalesRepository;
use App\Repositories\Eloquent\EloquentShopRepository;
use App\Repositories\Eloquent\EloquentSupplierRepository;
use App\Repositories\Eloquent\EloquentSyncRunRepository;
use App\Repositories\Eloquent\EloquentVariantRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Binds repository interfaces to their cached decorators (Cache -> Eloquent).
 */
class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShopRepositoryInterface::class, fn ($app) => new CachedShopRepository(
            new EloquentShopRepository,
            $app->make(Cache::class),
        ));

        $this->app->singleton(SyncRunRepositoryInterface::class, fn ($app) => new CachedSyncRunRepository(
            new EloquentSyncRunRepository,
            $app->make(Cache::class),
        ));

        $this->app->singleton(CatalogRepositoryInterface::class, fn ($app) => new CachedCatalogRepository(
            new EloquentCatalogRepository,
            $app->make(Cache::class),
        ));
        $this->app->singleton(VariantRepositoryInterface::class, fn ($app) => new CachedVariantRepository(
            new EloquentVariantRepository,
            $app->make(Cache::class),
        ));

        // Write-heavy bulk paths: no read cache to decorate.
        $this->app->singleton(DailySalesRepositoryInterface::class, EloquentDailySalesRepository::class);
        $this->app->singleton(ForecastRepositoryInterface::class, EloquentForecastRepository::class);
        $this->app->singleton(LocationSalesRepositoryInterface::class, EloquentLocationSalesRepository::class);
        $this->app->singleton(AlertLogRepositoryInterface::class, EloquentAlertLogRepository::class);

        $this->app->singleton(ForecastQueryRepositoryInterface::class, fn ($app) => new CachedForecastQueryRepository(
            new EloquentForecastQueryRepository,
            $app->make(Cache::class),
        ));
        $this->app->singleton(SupplierRepositoryInterface::class, fn ($app) => new CachedSupplierRepository(
            new EloquentSupplierRepository,
            $app->make(Cache::class),
        ));
        $this->app->singleton(AlertSettingRepositoryInterface::class, fn ($app) => new CachedAlertSettingRepository(
            new EloquentAlertSettingRepository,
            $app->make(Cache::class),
        ));
    }
}
