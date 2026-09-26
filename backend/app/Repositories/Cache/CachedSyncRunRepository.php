<?php

namespace App\Repositories\Cache;

use App\Enums\SyncStage;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Caches the latest run per shop (the progress bar polls it). SyncRunObserver
 * invalidates on every save, so progress updates show immediately.
 */
class CachedSyncRunRepository implements SyncRunRepositoryInterface
{
    public function __construct(
        private readonly SyncRunRepositoryInterface $inner,
        private readonly Cache $cache,
    ) {}

    public function latestForShop(Shop $shop): ?SyncRun
    {
        $key = CacheKeys::latestSyncRun($shop->id);
        $cached = $this->cache->get($key);
        if ($cached instanceof SyncRun) {
            return $cached;
        }

        $run = $this->inner->latestForShop($shop);
        if ($run !== null) {
            $this->cache->put($key, $run, CacheKeys::TTL_LATEST_SYNC_RUN);
        }

        return $run;
    }

    public function create(array $attributes): SyncRun
    {
        return $this->inner->create($attributes);
    }

    public function find(int $id): ?SyncRun
    {
        return $this->inner->find($id);
    }

    public function update(SyncRun $run, array $attributes): SyncRun
    {
        return $this->inner->update($run, $attributes);
    }

    public function activeForShop(Shop $shop): ?SyncRun
    {
        return $this->inner->activeForShop($shop);
    }

    public function findActiveByOperationId(Shop $shop, string $operationId): ?SyncRun
    {
        return $this->inner->findActiveByOperationId($shop, $operationId);
    }

    public function transitionStage(SyncRun $run, SyncStage $from, SyncStage $to, array $attributes = []): bool
    {
        return $this->inner->transitionStage($run, $from, $to, $attributes);
    }

    public function runningStartedBefore(Carbon $before): Collection
    {
        return $this->inner->runningStartedBefore($before);
    }

    public function consecutiveFailures(Shop $shop): int
    {
        return $this->inner->consecutiveFailures($shop);
    }

    public function pruneFinishedBefore(Carbon $before): int
    {
        return $this->inner->pruneFinishedBefore($before);
    }
}
