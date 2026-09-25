<?php

namespace App\Providers;

use App\Repositories\Cache\CachedShopRepository;
use App\Repositories\Cache\CachedSyncRunRepository;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Repositories\Eloquent\EloquentCatalogRepository;
use App\Repositories\Eloquent\EloquentDailySalesRepository;
use App\Repositories\Eloquent\EloquentShopRepository;
use App\Repositories\Eloquent\EloquentSyncRunRepository;
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

        // Write-heavy bulk import paths: no read cache to decorate.
        $this->app->singleton(CatalogRepositoryInterface::class, EloquentCatalogRepository::class);
        $this->app->singleton(DailySalesRepositoryInterface::class, EloquentDailySalesRepository::class);
    }
}
