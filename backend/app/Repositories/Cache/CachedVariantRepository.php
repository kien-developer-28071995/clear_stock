<?php

namespace App\Repositories\Cache;

use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/** Caches the bundle list; product setting and bundle writes bump the catalog version. */
class CachedVariantRepository implements VariantRepositoryInterface
{
    public function __construct(
        private readonly VariantRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function bundles(Shop $shop): Collection
    {
        return $this->cache->remember(
            CacheKeys::catalog($shop->id, CacheVersion::catalog($shop->id), 'bundles'),
            CacheKeys::TTL_CATALOG,
            fn () => $this->inner->bundles($shop),
        );
    }

    public function updateSettings(Variant $variant, array $settings): Variant
    {
        $variant = $this->inner->updateSettings($variant, $settings);
        CacheVersion::bumpCatalog($variant->shop_id);

        return $variant;
    }

    public function bulkUpdateSettings(Shop $shop, array $ids, array $settings): int
    {
        $updated = $this->inner->bulkUpdateSettings($shop, $ids, $settings);
        CacheVersion::bumpCatalog($shop->id);

        return $updated;
    }

    public function replaceManualComponents(Variant $bundle, array $components): void
    {
        $this->inner->replaceManualComponents($bundle, $components);
        CacheVersion::bumpCatalog($bundle->shop_id);
    }

    public function find(Shop $shop, int $id): ?Variant
    {
        return $this->inner->find($shop, $id);
    }

    public function search(Shop $shop, string $term, int $limit): Collection
    {
        return $this->inner->search($shop, $term, $limit);
    }

    public function findByShopifyIds(Shop $shop, array $shopifyVariantIds): Collection
    {
        return $this->inner->findByShopifyIds($shop, $shopifyVariantIds);
    }

    public function forImport(Shop $shop): Collection
    {
        return $this->inner->forImport($shop);
    }
}
