<?php

namespace App\Repositories\Cache;

use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Caching decorator. Tokens stay encrypted inside the cached model (the
 * "encrypted" cast stores ciphertext in the raw attributes).
 * Invalidation happens in ShopObserver.
 */
class CachedShopRepository implements ShopRepositoryInterface
{
    public function __construct(
        private readonly ShopRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function findById(int $id): ?Shop
    {
        return $this->remember(CacheKeys::shopById($id), fn () => $this->inner->findById($id));
    }

    public function findByDomain(string $domain): ?Shop
    {
        return $this->remember(CacheKeys::shopByDomain($domain), fn () => $this->inner->findByDomain($domain));
    }

    public function updateOrCreateByDomain(string $domain, array $attributes): Shop
    {
        return $this->inner->updateOrCreateByDomain($domain, $attributes);
    }

    public function update(Shop $shop, array $attributes): Shop
    {
        return $this->inner->update($shop, $attributes);
    }

    public function purge(Shop $shop): void
    {
        $this->inner->purge($shop); // ShopObserver::deleted clears the cache
    }

    private function remember(string $key, callable $resolve): ?Shop
    {
        $shop = $this->cache->get($key);
        if ($shop instanceof Shop) {
            return $shop;
        }

        $shop = $resolve();
        // Do not cache misses: a shop that is installing right now must be found next request.
        if ($shop !== null) {
            $this->cache->put($key, $shop, CacheKeys::TTL_SHOP);
        }

        return $shop;
    }
}
