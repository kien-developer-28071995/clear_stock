<?php

namespace App\Repositories\Contracts;

use App\Enums\SyncStage;
use App\Models\Shop;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

interface SyncRunRepositoryInterface
{
    public function create(array $attributes): SyncRun;

    public function find(int $id): ?SyncRun;

    public function update(SyncRun $run, array $attributes): SyncRun;

    public function latestForShop(Shop $shop): ?SyncRun;

    public function activeForShop(Shop $shop): ?SyncRun;

    /** Running sync of this shop that owns the given bulk operation gid. */
    public function findActiveByOperationId(Shop $shop, string $operationId): ?SyncRun;

    /** Atomically move a run from one stage to another; false if another worker got there first. */
    public function transitionStage(SyncRun $run, SyncStage $from, SyncStage $to, array $attributes = []): bool;

    /** @return Collection<int, SyncRun> running runs started before $before */
    public function runningStartedBefore(Carbon $before): Collection;

    public function pruneFinishedBefore(Carbon $before): int;
}
