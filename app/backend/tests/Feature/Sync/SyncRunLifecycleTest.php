<?php

use App\Enums\SyncRunStatus;
use App\Enums\SyncStage;
use App\Enums\SyncType;
use App\Events\ShopInstalled;
use App\Events\ShopSynced;
use App\Jobs\Sync\CheckSyncRun;
use App\Jobs\Sync\ProcessSyncRun;
use App\Jobs\Sync\StartSyncRun;
use App\Models\DailySale;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\Variant;
use App\Services\Sync\SyncService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Fake Shopify: GraphQL answered by operation name, bulk results served from $files.
 *
 * @param  array<string, string>  $bulkStatus  key => status (variants|inventory|orders)
 * @param  array<string, array>  $files  key => JSONL lines
 */
function fakeShopifySync(array $bulkStatus = [], array $files = []): void
{
    $keys = ['variants' => 1, 'inventory' => 2, 'orders' => 3];

    Http::fake(function (Request $request) use ($bulkStatus, $files, $keys) {
        if (str_starts_with($request->url(), 'https://storage.test/')) {
            $key = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response(implode("\n", array_map('json_encode', $files[$key] ?? [])));
        }

        $query = $request['query'] ?? '';
        if (str_contains($query, 'query Locations')) {
            return Http::response(['data' => ['locations' => [
                'nodes' => [['id' => gid('Location', 11), 'name' => 'Main', 'isActive' => true]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]);
        }
        if (str_contains($query, 'query Estimate')) {
            return Http::response(['data' => ['ordersCount' => ['count' => 10], 'productVariantsCount' => ['count' => 2], 'locationsCount' => ['count' => 1]]]);
        }
        if (str_contains($query, 'mutation RunBulkQuery')) {
            $key = match (true) {
                str_contains($request['variables']['query'], 'productVariants') => 'variants',
                str_contains($request['variables']['query'], 'inventoryItems') => 'inventory',
                default => 'orders',
            };

            return Http::response(['data' => ['bulkOperationRunQuery' => [
                'bulkOperation' => ['id' => gid('BulkOperation', $keys[$key]), 'status' => 'CREATED'], 'userErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'query BulkOperationStatus')) {
            $key = array_search((int) basename($request['variables']['id']), $keys, true);
            $status = $bulkStatus[$key] ?? 'RUNNING';

            return Http::response(['data' => ['bulkOperation' => [
                'id' => $request['variables']['id'], 'status' => $status, 'errorCode' => $status === 'FAILED' ? 'INTERNAL_SERVER_ERROR' : null,
                'objectCount' => '12', 'url' => $status === 'COMPLETED' && isset($files[$key]) ? "https://storage.test/{$key}" : null,
            ]]]);
        }

        return Http::response(['errors' => [['message' => 'unexpected query']]], 200);
    });
}

beforeEach(function () {
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'last_synced_at' => null]);
    $this->sync = app(SyncService::class);
});

it('starts an initial sync with a 400-day window and marks the shop as syncing', function () {
    Queue::fake();
    $this->travelTo('2026-09-20 10:00:00');

    $run = $this->sync->start($this->shop, SyncType::Manual);

    expect($run->type)->toBe(SyncType::Initial)
        ->and($run->window_start->toDateString())->toBe('2025-08-16')
        ->and($run->variants_updated_since)->toBeNull()
        ->and($this->shop->fresh()->sync_status->value)->toBe('running');
    Queue::assertPushed(StartSyncRun::class, fn ($job) => $job->runId === $run->id && $job->queue === 'sync');
});

it('never runs two syncs for the same shop at once', function () {
    Queue::fake();

    $first = $this->sync->start($this->shop, SyncType::Manual);
    $second = $this->sync->start($this->shop, SyncType::Manual);

    expect($second->id)->toBe($first->id);
    Queue::assertPushed(StartSyncRun::class, 1);
});

it('uses a 30-day window and changed variants only after the first sync', function () {
    Queue::fake();
    $this->travelTo('2026-09-23 03:00:00'); // Wednesday
    $this->shop->update(['last_synced_at' => '2026-09-22 02:00:00']);

    $run = $this->sync->start($this->shop->fresh(), SyncType::Nightly);

    expect($run->type)->toBe(SyncType::Nightly)
        ->and($run->window_start->toDateString())->toBe('2026-08-24')
        ->and($run->variants_updated_since->toDateTimeString())->toBe('2026-09-22 01:00:00');
});

it('re-imports the whole history window on a full resync', function () {
    Queue::fake();
    $this->travelTo('2026-09-23 03:00:00');
    $this->shop->update(['last_synced_at' => '2026-09-22 02:00:00']);

    $run = $this->sync->start($this->shop->fresh(), SyncType::Manual, full: true);

    expect($run->type)->toBe(SyncType::Manual)
        ->and($run->window_start->toDateString())->toBe('2025-08-19')
        ->and($run->variants_updated_since)->toBeNull();
});

it('refreshes the whole catalog on the weekly full-catalog day', function () {
    Queue::fake();
    $this->travelTo('2026-09-21 03:00:00'); // Monday
    $this->shop->update(['last_synced_at' => '2026-09-20 02:00:00']);

    expect($this->sync->start($this->shop->fresh(), SyncType::Nightly)->variants_updated_since)->toBeNull();
});

it('submits three bulk operations and starts polling', function () {
    Queue::fake();
    fakeShopifySync();
    $run = $this->sync->start($this->shop, SyncType::Initial);

    $this->sync->submit($run->id);

    $run->refresh();
    expect($run->stage)->toBe(SyncStage::Fetching)
        ->and(array_keys($run->operations))->toEqualCanonicalizing(['variants', 'inventory', 'orders']) // MySQL stores JSON keys in its own order
        ->and($run->stats['estimated_objects'])->toBe(10 * 3 + 2 * 3)
        ->and(Location::forShop($this->shop)->count())->toBe(1);
    Queue::assertPushed(CheckSyncRun::class);

    Http::assertSent(fn (Request $r) => str_contains($r['variables']['query'] ?? '', "processed_at:>='2025-"));
});

it('keeps polling while operations run and reports progress', function () {
    Queue::fake();
    fakeShopifySync(['variants' => 'COMPLETED', 'inventory' => 'RUNNING', 'orders' => 'RUNNING']);
    $run = $this->sync->start($this->shop, SyncType::Initial);
    $this->sync->submit($run->id);
    // A worker picking up the queued check releases its unique lock.
    (new UniqueLock(Cache::driver()))->release(new CheckSyncRun($run->id));

    $this->sync->check($run->id);

    $run->refresh();
    expect($run->stage)->toBe(SyncStage::Fetching)
        ->and($run->progress)->toBeGreaterThan(5)->toBeLessThan(60);
    Queue::assertPushed(CheckSyncRun::class, 2);
    Queue::assertNotPushed(ProcessSyncRun::class);
});

it('fails the run with a clear message when Shopify cannot export', function () {
    Queue::fake();
    fakeShopifySync(['orders' => 'FAILED']);
    $run = $this->sync->start($this->shop, SyncType::Initial);
    $this->sync->submit($run->id);

    (new CheckSyncRun($run->id))->handle($this->sync);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($this->shop->fresh()->sync_status->value)->toBe('failed')
        ->and($this->shop->fresh()->sync_error)->toMatchArray(['code' => 'export_failed'])
        ->and($this->shop->fresh()->sync_error['params'])->toMatchArray(['data' => 'orders', 'status' => 'FAILED']);
});

it('explains an access-denied export (protected customer data not approved)', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['bulkOperation' => [
        'id' => gid('BulkOperation', 3), 'status' => 'FAILED', 'errorCode' => 'ACCESS_DENIED', 'objectCount' => '0', 'url' => null,
    ]]])]);
    $run = $this->sync->start($this->shop, SyncType::Initial);
    $run->update(['stage' => SyncStage::Fetching, 'operations' => ['orders' => ['id' => gid('BulkOperation', 3), 'status' => 'RUNNING', 'object_count' => 0, 'url' => null, 'error_code' => null]]]);

    (new CheckSyncRun($run->id))->handle($this->sync);

    expect($this->shop->fresh()->sync_error)->toBe(['code' => 'export_access_denied', 'params' => ['data' => 'orders']]);
});

it('imports everything once all operations complete', function () {
    Queue::fake();
    Event::fake([ShopSynced::class]);
    $this->travelTo('2026-09-20 10:00:00');
    fakeShopifySync(['variants' => 'COMPLETED', 'inventory' => 'COMPLETED', 'orders' => 'COMPLETED'], [
        'variants' => [[
            'id' => gid('ProductVariant', 1), 'sku' => 'MUG', 'title' => 'Default Title', 'createdAt' => '2025-01-01T00:00:00Z',
            'requiresComponents' => false, 'product' => ['id' => gid('Product', 10), 'title' => 'Mug', 'status' => 'ACTIVE'],
            'inventoryItem' => ['id' => gid('InventoryItem', 100), 'tracked' => true, 'unitCost' => ['amount' => '3.00']],
        ]],
        'inventory' => [
            ['id' => gid('InventoryItem', 100), 'tracked' => true, 'variant' => ['id' => gid('ProductVariant', 1)]],
            ['location' => ['id' => gid('Location', 11)], 'quantities' => [['name' => 'available', 'quantity' => 0]], '__parentId' => gid('InventoryItem', 100)],
        ],
        'orders' => [
            ['id' => gid('Order', 5), 'processedAt' => '2026-09-17T12:00:00Z', 'cancelledAt' => null],
            ['quantity' => 4, 'currentQuantity' => 4, 'variant' => ['id' => gid('ProductVariant', 1)], 'lineItemGroup' => null, '__parentId' => gid('Order', 5)],
        ],
    ]);
    $this->shop->update(['sync_failure_notified_at' => now()->subDay()]);   // emailed about an earlier streak
    $run = $this->sync->start($this->shop, SyncType::Initial);
    $this->sync->submit($run->id);
    $this->sync->check($run->id);
    Queue::assertPushed(ProcessSyncRun::class);

    $this->sync->process($run->id);

    $run->refresh();
    $variant = Variant::forShop($this->shop)->first();
    expect($this->shop->fresh()->sync_failure_notified_at)->toBeNull()   // a new streak may email again
        ->and($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->progress)->toBe(100)
        ->and($variant->sku)->toBe('MUG')
        ->and(InventoryLevel::where('variant_id', $variant->id)->value('available'))->toBe(0)
        ->and(DailySale::where('variant_id', $variant->id)->where('date', '2026-09-17')->value('units_sold'))->toBe(4)
        // Sold out on the 17th: the following days are flagged out of stock.
        ->and(DailySale::where('variant_id', $variant->id)->where('date', '2026-09-18')->value('was_in_stock'))->toBeFalse()
        ->and($this->shop->fresh()->last_synced_at->equalTo($run->started_at))->toBeTrue()
        ->and($this->shop->fresh()->sync_status->value)->toBe('completed')
        ->and(glob(storage_path('app/private/sync/run-'.$run->id.'-*')))->toBe([]);
    Event::assertDispatched(ShopSynced::class);
});

it('handles an empty store (no bulk results)', function () {
    Queue::fake();
    fakeShopifySync(['variants' => 'COMPLETED', 'inventory' => 'COMPLETED', 'orders' => 'COMPLETED']);
    $run = $this->sync->start($this->shop, SyncType::Initial);
    $this->sync->submit($run->id);
    $this->sync->check($run->id);

    $this->sync->process($run->id);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed);
});

it('starts the first sync right after install', function () {
    Queue::fake();

    ShopInstalled::dispatch($this->shop);

    expect(SyncRun::forShop($this->shop)->count())->toBe(1);
});

it('checks the run immediately when Shopify reports a bulk operation finished', function () {
    Queue::fake();
    fakeShopifySync();
    $run = $this->sync->start($this->shop, SyncType::Initial);
    $this->sync->submit($run->id);
    Queue::fake(); // reset

    $this->sync->onBulkOperationFinished('demo.myshopify.com', gid('BulkOperation', 3));

    Queue::assertPushed(CheckSyncRun::class, fn ($job) => $job->runId === $run->id);
});
