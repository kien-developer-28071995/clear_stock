<?php

namespace App\Repositories\Cache;

use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Caches the dashboard queries, the product list (without a search term) and the
 * location list. Keys contain the shop's forecast version (bumped on every forecast
 * write), catalog version (products, settings, suppliers) and local date, so they
 * never serve stale data. Detail and exports read fresh.
 */
class CachedForecastQueryRepository implements ForecastQueryRepositoryInterface
{
    public function __construct(
        private readonly ForecastQueryRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function paginate(Shop $shop, array $filters, string $today, int $perPage, int $page): LengthAwarePaginator
    {
        // Searches are one-off and would fill the cache with single-use keys.
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            return $this->inner->paginate($shop, $filters, $today, $perPage, $page);
        }

        $hash = md5(json_encode([$filters['status'] ?? null, $filters['sort'] ?? null, $filters['location_id'] ?? null, $filters['vendor'] ?? null, $filters['product_type'] ?? null, $filters['abc'] ?? null, $perPage, $page]));
        $key = CacheKeys::forecastPage($shop->id, $this->forecastVersion($shop), CacheVersion::catalog($shop->id), $today, $hash);

        return $this->cache->remember($key, CacheKeys::TTL_DASHBOARD, fn () => $this->inner->paginate($shop, $filters, $today, $perPage, $page));
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
        return $this->cache->remember(
            CacheKeys::catalog($shop->id, CacheVersion::catalog($shop->id), 'locations'),
            CacheKeys::TTL_CATALOG,
            fn () => $this->inner->activeLocations($shop),
        );
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

    public function overstock(Shop $shop, string $today, int $limit): array
    {
        return $this->remember($shop, $today, "overstock{$limit}", fn () => $this->inner->overstock($shop, $today, $limit));
    }

    public function lostSales(Shop $shop, int $limit): array
    {
        return $this->remember($shop, 'any', "lost{$limit}", fn () => $this->inner->lostSales($shop, $limit));
    }

    public function planningRows(Shop $shop, array $filters): iterable
    {
        return $this->inner->planningRows($shop, $filters);
    }

    public function abcSummary(Shop $shop): array
    {
        return $this->remember($shop, 'any', 'abc', fn () => $this->inner->abcSummary($shop));
    }

    public function discontinuedStock(Shop $shop): array
    {
        return $this->remember($shop, 'any', 'discontinued', fn () => $this->inner->discontinuedStock($shop));
    }

    public function snapshotWeeks(Shop $shop, string $latestStart, int $limit): array
    {
        return $this->inner->snapshotWeeks($shop, $latestStart, $limit);
    }

    public function accuracyRows(Shop $shop, string $weekStart, int $horizonDays, ?int $variantId = null): array
    {
        // Past weeks' sales are final once synced; the forecast version changes after every sync.
        return $variantId !== null
            ? $this->inner->accuracyRows($shop, $weekStart, $horizonDays, $variantId)
            : $this->remember($shop, $weekStart, "accuracy{$horizonDays}", fn () => $this->inner->accuracyRows($shop, $weekStart, $horizonDays));
    }

    private function remember(Shop $shop, string $today, string $part, callable $resolve): mixed
    {
        return $this->cache->remember(CacheKeys::dashboard($shop->id, $this->forecastVersion($shop), $today).':'.$part, CacheKeys::TTL_DASHBOARD, $resolve);
    }

    private function forecastVersion(Shop $shop): int
    {
        return (int) $this->cache->get(CacheKeys::forecastVersion($shop->id), 0);
    }
}
