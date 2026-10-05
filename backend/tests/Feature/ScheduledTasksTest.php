<?php

use App\Exceptions\ShopifyApiException;
use App\Exceptions\SyncFailedException;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Jobs\NotifySyncFailing;
use App\Jobs\ReconcileBilling;
use App\Jobs\SendAlertDigest;
use App\Jobs\SendFlowTriggers;
use App\Jobs\SendRealtimeAlerts;
use App\Jobs\SendSupplierOrders;
use App\Jobs\SendWeeklySummary;
use App\Jobs\SyncRealtimeWebhook;
use App\Models\AlertSetting;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// The background work runs for every shop, every hour, whatever state a shop is in and whatever
// Shopify answers. One shop in a bad state (or Shopify having a bad day) must never stop the run
// for the others: each scheduled command finishes without throwing.

function shopsInEveryState(): void
{
    $healthy = Shop::factory()->create(['domain' => 'healthy.myshopify.com', 'timezone' => 'UTC', 'plan' => 'growth', 'access_token_expires_at' => now()->addYear(), 'last_synced_at' => now()->subHours(30), 'onboarded_at' => now()->subDays(40)]);
    $location = Location::factory()->for($healthy)->create();
    $supplier = Supplier::factory()->for($healthy)->create(['email' => 'orders@supplier.test', 'auto_email' => true]);
    product($healthy, $location, 'Mug', stock: 10, perDay: 4, attrs: ['supplier_id' => $supplier->id]);
    AlertSetting::factory()->for($healthy)->create(['email' => 'owner@healthy.test', 'enabled' => true, 'weekly_summary' => true]);
    app(ForecastService::class)->runForShop($healthy);

    Shop::factory()->create(['domain' => 'brand-new.myshopify.com', 'plan' => 'free', 'last_synced_at' => null, 'onboarded_at' => null]);                       // installed a minute ago
    Shop::factory()->create(['domain' => 'gone.myshopify.com', 'plan' => 'starter', 'uninstalled_at' => now()->subDay(), 'access_token' => null]);               // uninstalled
    Shop::factory()->create(['domain' => 'expired.myshopify.com', 'plan' => 'growth', 'access_token_expires_at' => now()->subYear(), 'refresh_token_expires_at' => now()->subDay()]);
    Shop::factory()->create(['domain' => 'odd.myshopify.com', 'plan' => 'growth', 'timezone' => 'Not/AZone', 'currency' => null, 'name' => null, 'sync_status' => 'failed']);
    AlertSetting::factory()->for(Shop::firstWhere('domain', 'odd.myshopify.com'))->create(['email' => null, 'enabled' => true]);
}

it('finishes every scheduled command for shops in every state, whatever Shopify answers', function (string $shopify, string $at) {
    Mail::fake();
    // The queue as in production: jobs wait. A command must finish for everyone; what a job does
    // with a failing shop is that job's own retry (tested with the jobs).
    Queue::fake();
    $this->travelTo($at);
    Http::fake(['*' => match ($shopify) {
        'fine' => Http::response(['data' => []]),
        'down' => Http::response('Service Unavailable', 503),
        'unauthorized' => Http::response(['errors' => '[API] Invalid API key or access token'], 401),
        'throttled' => Http::response(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]], 200),
        'nonsense' => Http::response('<html>not json</html>', 200),
        'unreachable' => fn () => throw new ConnectionException('Connection timed out'),
    }]);
    shopsInEveryState();

    $failed = [];
    foreach (['sync:nightly', 'forecast:nightly', 'alerts:send', 'alerts:realtime-sync', 'suppliers:send-orders', 'sync:maintenance', 'billing:reconcile'] as $command) {
        try {
            Artisan::call($command);
        } catch (Throwable $e) {
            $failed[] = "{$command} ({$shopify}): ".get_class($e).': '.substr($e->getMessage(), 0, 160);
        }
    }

    expect($failed)->toBe([]);
})->with(['fine', 'down', 'unauthorized', 'throttled', 'nonsense', 'unreachable'])
    ->with(['2026-09-21 08:05:00', '2026-09-20 23:59:59']);   // a Monday morning (weekly things are due), a Sunday midnight

it('reads an unknown timezone as UTC instead of failing', function () {
    $shop = Shop::factory()->create(['timezone' => 'Mars/Olympus_Mons']);

    expect($shop->fresh()->timezone)->toBe('UTC')
        ->and(Shop::factory()->create(['timezone' => 'Asia/Ho_Chi_Minh'])->fresh()->timezone)->toBe('Asia/Ho_Chi_Minh');
});

it('runs every background job for every kind of shop: done, or a failure Shopify caused, never a crash of our own', function (string $shopify) {
    Mail::fake();
    $this->travelTo('2026-09-21 08:05:00');
    Http::fake(['*' => match ($shopify) {
        'fine' => Http::response(['data' => []]),
        'down' => Http::response('Service Unavailable', 503),
        'unauthorized' => Http::response(['errors' => '[API] Invalid API key or access token'], 401),
        'nonsense' => Http::response('<html>not json</html>', 200),
        'unreachable' => fn () => throw new ConnectionException('Connection timed out'),
    }]);
    shopsInEveryState();
    $ids = [...Shop::query()->pluck('id')->all(), 999999];   // and a shop that no longer exists (deleted while the job waited)

    $crashes = [];
    foreach ([
        RecomputeForecasts::class, SendAlertDigest::class, SendWeeklySummary::class, SendSupplierOrders::class,
        ReconcileBilling::class, SyncRealtimeWebhook::class, SendRealtimeAlerts::class, SendFlowTriggers::class, NotifySyncFailing::class,
    ] as $job) {
        if (! class_exists($job)) {
            $crashes[] = "{$job} does not exist";

            continue;
        }
        foreach ($ids as $id) {
            try {
                app()->call([new $job($id), 'handle']);
            } catch (ShopifyApiException|SyncFailedException $e) {
                // Shopify's failure: the queue retries the job with backoff.
            } catch (Throwable $e) {
                $domain = Shop::find($id)?->domain ?? 'deleted shop';
                $crashes[] = class_basename($job)." for {$domain} ({$shopify}): ".get_class($e).': '.substr($e->getMessage(), 0, 140);
            }
        }
    }

    expect($crashes)->toBe([]);
})->with(['fine', 'down', 'unauthorized', 'nonsense', 'unreachable']);
