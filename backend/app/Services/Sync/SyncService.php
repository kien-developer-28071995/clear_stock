<?php

namespace App\Services\Sync;

use App\Enums\BulkQueryKey;
use App\Enums\SyncRunStatus;
use App\Enums\SyncStage;
use App\Enums\SyncType;
use App\Events\ShopSynced;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyReauthorizeException;
use App\Exceptions\SyncFailedException;
use App\Jobs\Sync\CheckSyncRun;
use App\Jobs\Sync\ProcessSyncRun;
use App\Jobs\Sync\StartSyncRun;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Services\Shopify\AdminApiClient;
use App\Services\Shopify\BulkOperation;
use App\Services\Shopify\BulkOperationClient;
use App\Support\CacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates a sync run:
 *   start()   create the run, queue StartSyncRun
 *   submit()  import locations, launch 3 concurrent bulk operations (variants, inventory, orders)
 *   check()   poll them (also triggered by the bulk_operations/finish webhook)
 *   process() download + import in order, rebuild stock history, fire ShopSynced
 */
class SyncService
{
    public function __construct(
        private readonly SyncRunRepositoryInterface $runs,
        private readonly ShopRepositoryInterface $shops,
        private readonly BulkOperationClient $bulk,
        private readonly AdminApiClient $admin,
        private readonly LocationImporter $locations,
        private readonly VariantImporter $variants,
        private readonly InventoryImporter $inventory,
        private readonly OrderAggregator $orders,
        private readonly StockHistoryBuilder $stockHistory,
        private readonly Cache $cache,
    ) {}

    public function latest(Shop $shop): ?SyncRun
    {
        return $this->runs->latestForShop($shop);
    }

    /** Start a sync unless one is already running (then that one is returned). */
    public function start(Shop $shop, SyncType $type): SyncRun
    {
        if ($active = $this->runs->activeForShop($shop)) {
            return $active;
        }

        $now = CarbonImmutable::now();
        $today = $now->setTimezone($shop->timezone)->startOfDay();
        $firstSync = $shop->last_synced_at === null;
        $type = $firstSync ? SyncType::Initial : $type;

        $windowStart = $firstSync
            ? $today->subDays(config('sync.initial_days'))
            // Re-aggregate the recent window, or everything since the last sync if that is older.
            : min($today->subDays(config('sync.nightly_window_days')), CarbonImmutable::parse($shop->last_synced_at)->setTimezone($shop->timezone)->startOfDay()->subDay());
        $windowStart = max($windowStart, $today->subDays(config('sync.initial_days')));

        $fullCatalog = $firstSync || $today->isoWeekday() === config('sync.full_catalog_weekday');

        $run = $this->runs->create([
            'shop_id' => $shop->id,
            'type' => $type,
            'status' => SyncRunStatus::Running,
            'stage' => SyncStage::Queued,
            'progress' => 0,
            'window_start' => $windowStart->toDateString(),
            'variants_updated_since' => $fullCatalog ? null : CarbonImmutable::parse($shop->last_synced_at)->subHour(),
            'started_at' => $now,
        ]);

        $this->shops->update($shop, ['sync_status' => SyncRunStatus::Running->value, 'sync_error' => null]);
        StartSyncRun::dispatch($run->id);

        Log::info('Sync started', ['shop' => $shop->domain, 'run' => $run->id, 'type' => $type->value, 'window_start' => $run->window_start->toDateString()]);

        return $run;
    }

    /** Launch the bulk operations. */
    public function submit(int $runId): void
    {
        [$run, $shop] = $this->load($runId);
        if ($run === null || $run->stage !== SyncStage::Queued) {
            return;
        }

        $this->locations->import($shop);
        $estimate = $this->estimateObjects($shop, $run);

        $windowStartIso = CarbonImmutable::parse($run->window_start->toDateString(), $shop->timezone)->toIso8601String();
        $queries = [
            BulkQueryKey::Variants->value => BulkQueries::variants($run->variants_updated_since?->toIso8601String()),
            BulkQueryKey::Inventory->value => BulkQueries::inventory(),
            BulkQueryKey::Orders->value => BulkQueries::orders($windowStartIso),
        ];

        $operations = [];
        foreach ($queries as $key => $query) {
            $op = $this->bulk->run($shop, $query);
            $operations[$key] = ['id' => $op->id, 'status' => $op->status, 'object_count' => 0, 'url' => null, 'error_code' => null];
        }

        if ($this->runs->transitionStage($run, SyncStage::Queued, SyncStage::Fetching, [
            'operations' => $operations,
            'stats' => ['estimated_objects' => $estimate],
        ])) {
            CheckSyncRun::dispatch($run->id)->delay(now()->addSeconds(5));
        }
    }

    /** Poll bulk operations; queue processing when all are done. */
    public function check(int $runId): void
    {
        [$run, $shop] = $this->load($runId);
        if ($run === null || $run->stage !== SyncStage::Fetching) {
            return;
        }

        $operations = $run->operations ?? [];
        foreach ($operations as $key => $op) {
            if ($this->isFinished($op['status'])) {
                continue;
            }
            $status = $this->bulk->status($shop, $op['id']);
            $operations[$key] = ['id' => $status->id, 'status' => $status->status, 'object_count' => $status->objectCount, 'url' => $status->url, 'error_code' => $status->errorCode];

            if ($status->isFailed()) {
                $this->runs->update($run, ['operations' => $operations]);
                throw new SyncFailedException("Shopify could not export your {$key} ({$status->status}".($status->errorCode ? ", {$status->errorCode}" : '').').');
            }
        }

        $done = collect($operations)->every(fn ($op) => $op['status'] === 'COMPLETED');
        if ($done) {
            $this->runs->update($run, ['operations' => $operations]);
            if ($this->runs->transitionStage($run, SyncStage::Fetching, SyncStage::ImportingCatalog)) {
                ProcessSyncRun::dispatch($run->id);
            }

            return;
        }

        $this->runs->update($run, ['operations' => $operations, 'progress' => $this->fetchProgress($run, $operations)]);
        $elapsed = $run->started_at->diffInSeconds(now());
        CheckSyncRun::dispatch($run->id)->delay(now()->addSeconds($elapsed < 120 ? 5 : 20));
    }

    /** Download results and import them. Every step is idempotent, so a retried job simply redoes it. */
    public function process(int $runId): void
    {
        $lock = $this->cache->lock(CacheKeys::syncRunLock($runId), 3600);
        if (! $lock->get()) {
            return; // another worker is processing this run
        }

        $files = [];
        try {
            [$run, $shop] = $this->load($runId);
            if ($run === null || ! in_array($run->stage, [SyncStage::ImportingCatalog, SyncStage::ImportingInventory, SyncStage::ImportingOrders, SyncStage::RebuildingStock], true)) {
                return;
            }

            foreach (BulkQueryKey::cases() as $key) {
                $files[$key->value] = $this->downloadResult($run, $key);
            }

            $stats = $run->stats ?? [];
            $this->stage($run, SyncStage::ImportingCatalog);
            $stats['catalog'] = $this->variants->import($shop, $files['variants']);

            $this->stage($run, SyncStage::ImportingInventory);
            $stats['inventory'] = $this->inventory->import($shop, $files['inventory'], $run->started_at);

            $this->stage($run, SyncStage::ImportingOrders);
            $stats['orders'] = $this->orders->import($shop, $files['orders'], $run->window_start->toDateString());

            $this->stage($run, SyncStage::RebuildingStock);
            $stats['stock'] = $this->stockHistory->rebuild($shop, $run->window_start->toDateString());

            $this->runs->update($run, [
                'status' => SyncRunStatus::Completed,
                'stage' => SyncStage::Completed,
                'progress' => 100,
                'stats' => $stats,
                'finished_at' => now(),
            ]);
            $shop = $this->shops->update($shop, [
                'sync_status' => SyncRunStatus::Completed->value,
                'sync_error' => null,
                'last_synced_at' => $run->started_at,
            ]);

            Log::info('Sync completed', ['shop' => $shop->domain, 'run' => $run->id, 'stats' => $stats]);
            ShopSynced::dispatch($shop, $run);
        } finally {
            foreach (array_filter($files) as $file) {
                @unlink($file);
            }
            $lock->release();
        }
    }

    /** bulk_operations/finish webhook: check the owning run right away instead of waiting for the next poll. */
    public function onBulkOperationFinished(string $shopDomain, string $operationId): void
    {
        $shop = $this->shops->findByDomain($shopDomain);
        $run = $shop ? $this->runs->findActiveByOperationId($shop, $operationId) : null;

        if ($run !== null && $run->stage === SyncStage::Fetching) {
            CheckSyncRun::dispatch($run->id);
        }
    }

    /** Mark a run failed and surface a merchant-friendly message. */
    public function fail(int $runId, Throwable $e): void
    {
        [$run, $shop] = $this->load($runId);
        if ($run === null || ! $run->isRunning()) {
            return;
        }

        $message = match (true) {
            $e instanceof SyncFailedException => $e->getMessage(),
            $e instanceof ShopifyReauthorizeException => 'We lost access to your store. Please open the app again to reconnect.',
            $e instanceof ShopifyApiException => 'Shopify did not respond as expected. We will retry automatically tonight, or you can retry now.',
            default => 'Something went wrong while syncing. We will retry automatically tonight, or you can retry now.',
        };

        $this->runs->update($run, [
            'status' => SyncRunStatus::Failed,
            'stage' => SyncStage::Failed,
            'error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 2000),
            'finished_at' => now(),
        ]);
        $this->shops->update($shop, ['sync_status' => SyncRunStatus::Failed->value, 'sync_error' => $message]);

        Log::error('Sync failed', ['shop' => $shop->domain, 'run' => $runId, 'error' => $e->getMessage()]);
    }

    /** @return array{0: ?SyncRun, 1: ?Shop} */
    private function load(int $runId): array
    {
        $run = $this->runs->find($runId);
        $shop = $run ? $this->shops->findById($run->shop_id) : null;

        if ($run === null || $shop === null || ! $run->isRunning()) {
            return [null, null];
        }

        return [$run, $shop];
    }

    private function stage(SyncRun $run, SyncStage $stage): void
    {
        $this->runs->update($run, ['stage' => $stage, 'progress' => $stage->startProgress()]);
    }

    private function downloadResult(SyncRun $run, BulkQueryKey $key): ?string
    {
        $op = $run->operation($key);
        $url = $op['url'] ?? null;
        if ($url === null) {
            return null; // query matched nothing
        }

        $dir = storage_path('app/private/sync');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = "{$dir}/run-{$run->id}-{$key->value}.jsonl";
        $this->bulk->download($url, $path);

        return $path;
    }

    private function isFinished(string $status): bool
    {
        return in_array($status, [...BulkOperation::DONE_OK, ...BulkOperation::DONE_ERROR], true);
    }

    /** Fetch stage covers 5% -> 60%, proportional to exported objects vs. an estimate. */
    private function fetchProgress(SyncRun $run, array $operations): int
    {
        $estimate = (int) ($run->stats['estimated_objects'] ?? 0);
        $fetched = array_sum(array_column($operations, 'object_count'));
        $from = SyncStage::Fetching->startProgress();
        $to = SyncStage::ImportingCatalog->startProgress() - 2;

        if ($estimate <= 0) {
            return $from;
        }

        return (int) min($to, $from + ($to - $from) * $fetched / $estimate);
    }

    /** Rough object count for the progress bar: orders x3 (order + ~2 lines) + variants x (1 + locations). */
    private function estimateObjects(Shop $shop, SyncRun $run): int
    {
        try {
            $windowStartIso = CarbonImmutable::parse($run->window_start->toDateString(), $shop->timezone)->toIso8601String();
            $data = $this->admin->query($shop, <<<'GQL'
                query Estimate($orders: String!) {
                  ordersCount(query: $orders, limit: null) { count }
                  productVariantsCount(limit: null) { count }
                  locationsCount { count }
                }
                GQL, ['orders' => "processed_at:>='{$windowStartIso}'"]);

            $orders = (int) ($data['ordersCount']['count'] ?? 0);
            $variants = (int) ($data['productVariantsCount']['count'] ?? 0);
            $locations = max(1, (int) ($data['locationsCount']['count'] ?? 1));

            return $orders * 3 + $variants * (2 + $locations);
        } catch (ShopifyApiException $e) {
            Log::info('Sync estimate unavailable', ['shop' => $shop->domain, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
