<?php

namespace App\Repositories\Cache;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Catalog writes (sync imports) bump the shop's catalog version, which invalidates
 * every catalog read cache. Caches the tracked-SKU count (plans page, limits).
 * The bulk reads used by sync and forecasting run once per job and stay uncached.
 */
class CachedCatalogRepository implements CatalogRepositoryInterface
{
    public function __construct(
        private readonly CatalogRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function countTrackedVariants(Shop $shop): int
    {
        return (int) $this->cache->remember(
            CacheKeys::catalog($shop->id, CacheVersion::catalog($shop->id), 'tracked-count'),
            CacheKeys::TTL_CATALOG,
            fn () => $this->inner->countTrackedVariants($shop),
        );
    }

    // --- writes: bump the catalog version ------------------------------------------------

    public function upsertLocations(Shop $shop, array $rows): void
    {
        $this->inner->upsertLocations($shop, $rows);
        CacheVersion::bumpCatalog($shop->id);
    }

    public function upsertVariants(Shop $shop, array $rows): void
    {
        $this->inner->upsertVariants($shop, $rows);
        CacheVersion::bumpCatalog($shop->id);
    }

    public function replaceShopifyBundleComponents(Shop $shop, array $bundleVariantIds, array $components): void
    {
        $this->inner->replaceShopifyBundleComponents($shop, $bundleVariantIds, $components);
        CacheVersion::bumpCatalog($shop->id);
    }

    public function upsertInventoryLevels(Shop $shop, array $rows): void
    {
        $this->inner->upsertInventoryLevels($shop, $rows);
        CacheVersion::bumpCatalog($shop->id);
    }

    public function deleteInventoryLevelsNotSeenSince(Shop $shop, Carbon $since): int
    {
        $deleted = $this->inner->deleteInventoryLevelsNotSeenSince($shop, $since);
        CacheVersion::bumpCatalog($shop->id);

        return $deleted;
    }

    public function deactivateVariantsNotIn(Shop $shop, array $variantIds): int
    {
        $deactivated = $this->inner->deactivateVariantsNotIn($shop, $variantIds);
        CacheVersion::bumpCatalog($shop->id);

        return $deactivated;
    }

    public function updateTracked(Shop $shop, array $rows): void
    {
        $this->inner->updateTracked($shop, $rows);
        CacheVersion::bumpCatalog($shop->id);
    }

    // --- reads used by sync / forecasting: not cached -----------------------------------

    public function activeLocationIds(Shop $shop): array
    {
        return $this->inner->activeLocationIds($shop);
    }

    public function variantIdMap(Shop $shop): array
    {
        return $this->inner->variantIdMap($shop);
    }

    public function stockByVariant(Shop $shop): array
    {
        return $this->inner->stockByVariant($shop);
    }

    public function stockByVariantAndLocation(Shop $shop): array
    {
        return $this->inner->stockByVariantAndLocation($shop);
    }

    public function incomingByVariant(Shop $shop): array
    {
        return $this->inner->incomingByVariant($shop);
    }

    public function incomingByVariantAndLocation(Shop $shop): array
    {
        return $this->inner->incomingByVariantAndLocation($shop);
    }

    public function activeVariantInfo(Shop $shop): array
    {
        return $this->inner->activeVariantInfo($shop);
    }
}
