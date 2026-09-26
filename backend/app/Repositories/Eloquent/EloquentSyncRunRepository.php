<?php

namespace App\Repositories\Eloquent;

use App\Enums\SyncRunStatus;
use App\Enums\SyncStage;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Support\CacheKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EloquentSyncRunRepository implements SyncRunRepositoryInterface
{
    public function create(array $attributes): SyncRun
    {
        return SyncRun::query()->create($attributes);
    }

    public function find(int $id): ?SyncRun
    {
        return SyncRun::query()->withoutGlobalScope('shop')->find($id);
    }

    public function update(SyncRun $run, array $attributes): SyncRun
    {
        $run->fill($attributes)->save();

        return $run;
    }

    public function latestForShop(Shop $shop): ?SyncRun
    {
        return SyncRun::query()->forShop($shop)->latest('id')->first();
    }

    public function activeForShop(Shop $shop): ?SyncRun
    {
        return SyncRun::query()->forShop($shop)->where('status', SyncRunStatus::Running)->latest('id')->first();
    }

    public function findActiveByOperationId(Shop $shop, string $operationId): ?SyncRun
    {
        return SyncRun::query()->forShop($shop)
            ->where('status', SyncRunStatus::Running)
            ->get()
            ->first(fn (SyncRun $run) => collect($run->operations ?? [])->contains(fn ($op) => ($op['id'] ?? null) === $operationId));
    }

    public function transitionStage(SyncRun $run, SyncStage $from, SyncStage $to, array $attributes = []): bool
    {
        $attributes += ['stage' => $to, 'progress' => $to->startProgress()];

        $updated = SyncRun::query()->withoutGlobalScope('shop')
            ->whereKey($run->id)
            ->where('stage', $from)
            ->where('status', SyncRunStatus::Running)
            ->update($this->castForUpdate($run, $attributes));

        if ($updated === 1) {
            $run->refresh();
            // Query-builder updates skip model events, so invalidate what SyncRunObserver would.
            Cache::forget(CacheKeys::latestSyncRun($run->shop_id));
        }

        return $updated === 1;
    }

    public function runningStartedBefore(Carbon $before): Collection
    {
        return SyncRun::query()->withoutGlobalScope('shop')
            ->where('status', SyncRunStatus::Running)
            ->where('started_at', '<', $before)
            ->get();
    }

    public function consecutiveFailures(Shop $shop): int
    {
        $lastCompleted = SyncRun::query()->forShop($shop)->where('status', SyncRunStatus::Completed)->max('id');

        return SyncRun::query()->forShop($shop)->where('status', SyncRunStatus::Failed)
            ->when($lastCompleted !== null, fn ($q) => $q->where('id', '>', $lastCompleted))
            ->count();
    }

    public function pruneFinishedBefore(Carbon $before): int
    {
        return SyncRun::query()->withoutGlobalScope('shop')
            ->where('status', '!=', SyncRunStatus::Running)
            ->where('started_at', '<', $before)
            ->delete();
    }

    /** Query-builder updates bypass casts: convert enums/arrays like the model would. */
    private function castForUpdate(SyncRun $run, array $attributes): array
    {
        $model = $run->newInstance()->forceFill($attributes);

        return collect($attributes)->mapWithKeys(fn ($v, $k) => [$k => $model->getAttributes()[$k]])->all();
    }
}
