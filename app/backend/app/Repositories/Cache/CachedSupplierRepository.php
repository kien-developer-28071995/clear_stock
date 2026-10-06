<?php

namespace App\Repositories\Cache;

use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/** Caches the supplier list (used in pickers on many screens). SupplierObserver invalidates it. */
class CachedSupplierRepository implements SupplierRepositoryInterface
{
    public function __construct(
        private readonly SupplierRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function allForShop(Shop $shop): Collection
    {
        return $this->cache->remember(CacheKeys::suppliers($shop->id), CacheKeys::TTL_SUPPLIERS, fn () => $this->inner->allForShop($shop));
    }

    public function find(Shop $shop, int $id): ?Supplier
    {
        return $this->inner->find($shop, $id);
    }

    public function create(Shop $shop, array $attributes): Supplier
    {
        return $this->inner->create($shop, $attributes);
    }

    public function update(Supplier $supplier, array $attributes): Supplier
    {
        return $this->inner->update($supplier, $attributes);
    }

    public function delete(Supplier $supplier): void
    {
        $this->inner->delete($supplier);
    }
}
