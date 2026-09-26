<?php

namespace App\Repositories\Cache;

use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Caches the dashboard queries. Keys contain the shop's forecast version (bumped
 * on every forecast write) and the local date, so they never serve stale data.
 * The paginated list and detail are cheap indexed queries and are not cached.
 */
class CachedForecastQueryRepository implements ForecastQueryRepositoryInterface
{
    public function __construct(
        private readonly ForecastQueryRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function paginate(Shop $shop, array $filters, string $today, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->inner->paginate($shop, $filters, $today, $perPage, $page);
    }

    public function findForVariant(Shop $shop, int $variantId): ?Forecast
    {
        return $this->inner->findForVariant($shop, $variantId);
    }

    public function byLocation(Shop $shop, int $variantId): array
    {
        return $this->inner->byLocation($shop, $variantId);
    }

    public function activeLocations(Shop $shop): array
    {
        return $this->inner->activeLocations($shop);
    }

    public function reorderList(Shop $shop, string $today, ?int $supplierId, ?int $locationId = null, ?array $variantIds = null): Collection
    {
        return $this->inner->reorderList($shop, $today, $supplierId, $locationId, $variantIds);
    }

    public function counts(Shop $shop, string $today): array
    {
        return $this->remember($shop, $today, 'counts', fn () => $this->inner->counts($shop, $today));
    }

    public function actionItems(Shop $shop, string $until, int $limit): Collection
    {
        return $this->remember($shop, $until, "actions{$limit}", fn () => $this->inner->actionItems($shop, $until, $limit));
    }

    public function runway(Shop $shop, int $limit): Collection
    {
        return $this->remember($shop, 'any', "runway{$limit}", fn () => $this->inner->runway($shop, $limit));
    }

    public function slowMovers(Shop $shop, int $limit): array
    {
        return $this->remember($shop, 'any', "slow{$limit}", fn () => $this->inner->slowMovers($shop, $limit));
    }

    private function remember(Shop $shop, string $today, string $part, callable $resolve): mixed
    {
        $version = (int) $this->cache->get(CacheKeys::forecastVersion($shop->id), 0);

        return $this->cache->remember(CacheKeys::dashboard($shop->id, $version, $today).':'.$part, CacheKeys::TTL_DASHBOARD, $resolve);
    }
}
