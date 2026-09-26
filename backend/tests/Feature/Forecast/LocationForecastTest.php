<?php

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Enums\SyncType;
use App\Jobs\Sync\StartSyncRun;
use App\Models\Forecast;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\Variant;
use App\Services\App\BillingService;
use App\Services\Forecast\ForecastService;
use App\Services\Sync\BulkQueries;
use App\Services\Sync\OrderAggregator;
use App\Services\Sync\StockHistoryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const FO_SCOPES = 'read_products,read_inventory,read_locations,read_orders,read_all_orders,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders';

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'growth', 'scopes' => FO_SCOPES]);
    $this->hanoi = Location::factory()->for($this->shop)->create(['shopify_location_id' => 11, 'name' => 'Hanoi']);
    $this->saigon = Location::factory()->for($this->shop)->create(['shopify_location_id' => 22, 'name' => 'Saigon']);
    $this->mug = Variant::factory()->for($this->shop)->create(['shopify_variant_id' => 101, 'product_title' => 'Mug', 'title' => 'Default Title', 'shopify_created_at' => '2025-01-01']);
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $this->mug->id, 'location_id' => $this->hanoi->id, 'available' => 40]);
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $this->mug->id, 'location_id' => $this->saigon->id, 'available' => 2]);
});

/** Order with one line fulfilled from a location (fulfillment order). */
function orderAt(int $order, string $day, array $fulfilments, bool $cancelledFo = false): array
{
    $lines = [['id' => gid('Order', $order), 'processedAt' => "{$day}T12:00:00Z", 'cancelledAt' => null]];
    foreach ($fulfilments as $i => [$location, $qty]) {
        $fo = $order * 10 + $i;
        $lines[] = ['quantity' => $qty, 'currentQuantity' => $qty, 'variant' => ['id' => gid('ProductVariant', 101)], 'lineItemGroup' => null, '__parentId' => gid('Order', $order)];
        $lines[] = ['id' => gid('FulfillmentOrder', $fo), 'status' => $cancelledFo ? 'CANCELLED' : 'CLOSED', 'assignedLocation' => ['location' => ['id' => gid('Location', $location)]], '__parentId' => gid('Order', $order)];
        $lines[] = ['totalQuantity' => $qty, 'variant' => ['id' => gid('ProductVariant', 101)], '__parentId' => gid('FulfillmentOrder', $fo)];
    }

    return $lines;
}

/** 100 days: Hanoi sells 3/day, Saigon 1/day. */
function seedLocationSales(): string
{
    $lines = [];
    for ($ago = 1; $ago <= 100; $ago++) {
        $day = CarbonImmutable::parse('2026-09-20')->subDays($ago)->toDateString();
        $lines = [...$lines, ...orderAt(1000 + $ago, $day, [[11, 3], [22, 1]])];
    }

    return jsonlFile($lines);
}

it('adds fulfillment orders to the orders bulk query only when asked', function () {
    expect(BulkQueries::orders('2026-01-01'))->not->toContain('fulfillmentOrders')
        ->and(BulkQueries::orders('2026-01-01', true))->toContain('fulfillmentOrders')->toContain('assignedLocation');
});

it('aggregates units per fulfilling location and ignores cancelled fulfillment orders', function () {
    $file = jsonlFile([
        ...orderAt(1, '2026-09-10', [[11, 2], [22, 5]]),
        ...orderAt(2, '2026-09-10', [[11, 4]], cancelledFo: true),
        ...orderAt(3, '2026-09-10', [[99, 7]]), // unknown/inactive location
    ]);

    $stats = app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01', withLocations: true);

    $rows = DB::table('location_daily_sales')->get()->mapWithKeys(fn ($r) => [$r->location_id => $r->units_sold])->all();
    expect($rows)->toBe([$this->hanoi->id => 2, $this->saigon->id => 5])
        ->and($stats['location_rows'])->toBe(2)
        // The combined total still counts every line item.
        ->and((int) DB::table('daily_sales')->where('variant_id', $this->mug->id)->value('units_sold'))->toBe(18);
});

it('forecasts each location from its own sales and stock', function () {
    app(OrderAggregator::class)->import($this->shop, seedLocationSales(), '2026-06-01', withLocations: true);
    app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-06-01');
    app(StockHistoryBuilder::class)->rebuildLocations($this->shop, '2026-06-01');

    $stats = app(ForecastService::class)->runForShop($this->shop);

    $byLocation = Forecast::whereNotNull('location_id')->get()->keyBy('location_id');
    expect($stats['location_forecasts'])->toBe(2)
        ->and((float) Forecast::whereNull('location_id')->value('avg_daily_sales'))->toBe(4.0)
        ->and((float) $byLocation[$this->hanoi->id]->avg_daily_sales)->toBe(3.0)
        ->and($byLocation[$this->hanoi->id]->current_stock)->toBe(40)
        ->and((float) $byLocation[$this->saigon->id]->avg_daily_sales)->toBe(1.0)
        ->and($byLocation[$this->saigon->id]->reorder_date->toDateString())->toBe('2026-09-20'); // 2 left, needs 21
});

it('marks out-of-stock days per location', function () {
    InventoryLevel::where('location_id', $this->saigon->id)->update(['available' => 0]);
    // Saigon sold its last unit 5 days ago.
    $lines = [];
    for ($ago = 5; $ago <= 30; $ago++) {
        $lines = [...$lines, ...orderAt(2000 + $ago, CarbonImmutable::parse('2026-09-20')->subDays($ago)->toDateString(), [[22, 1]])];
    }
    app(OrderAggregator::class)->import($this->shop, jsonlFile($lines), '2026-08-01', withLocations: true);

    $stats = app(StockHistoryBuilder::class)->rebuildLocations($this->shop, '2026-08-01');

    expect($stats['out_of_stock_days'])->toBe(5) // Sep 16..20
        ->and(DB::table('location_daily_sales')->where('location_id', $this->saigon->id)->where('was_in_stock', false)->count())->toBe(5);
});

it('only produces location forecasts on Growth with 2+ locations and the scopes', function (array $shopAttrs, bool $secondLocationActive) {
    app(OrderAggregator::class)->import($this->shop, seedLocationSales(), '2026-06-01', withLocations: true);
    $this->shop->update($shopAttrs);
    $this->saigon->update(['is_active' => $secondLocationActive]);

    app(ForecastService::class)->runForShop($this->shop->fresh());

    expect(Forecast::whereNotNull('location_id')->count())->toBe(0);
})->with([
    'starter plan' => [['plan' => 'starter'], true],
    'scopes not granted yet' => [['scopes' => 'read_products,read_inventory,read_locations,read_orders,read_all_orders'], true],
    'single location' => [[], false],
]);

it('removes location forecasts after a downgrade', function () {
    app(OrderAggregator::class)->import($this->shop, seedLocationSales(), '2026-06-01', withLocations: true);
    app(ForecastService::class)->runForShop($this->shop);
    expect(Forecast::whereNotNull('location_id')->count())->toBe(2);

    $this->shop->update(['plan' => 'starter']);
    app(ForecastService::class)->runForShop($this->shop->fresh());

    expect(Forecast::whereNotNull('location_id')->count())->toBe(0)
        ->and(Forecast::whereNull('location_id')->count())->toBe(1);
});

it('re-imports history when a shop upgrades to Growth', function () {
    $this->shop->update(['plan' => 'starter', 'last_synced_at' => now()->subDay()]);
    Http::fake(['*' => Http::response(['data' => ['currentAppInstallation' => ['activeSubscriptions' => [[
        'id' => 'gid://shopify/AppSubscription/9', 'status' => 'ACTIVE', 'test' => true, 'currentPeriodEnd' => null,
        'name' => BillingService::subscriptionName(Plan::Growth, PlanInterval::Monthly),
    ]]]]])]);

    app(BillingService::class)->refresh($this->shop->fresh());

    $run = SyncRun::forShop($this->shop)->latest('id')->first();
    expect($run->type)->toBe(SyncType::Manual)
        ->and($run->window_start->toDateString())->toBe('2025-08-16');
    Queue::assertPushed(StartSyncRun::class);
});

describe('API', function () {
    beforeEach(function () {
        app(OrderAggregator::class)->import($this->shop, seedLocationSales(), '2026-06-01', withLocations: true);
        app(ForecastService::class)->runForShop($this->shop);
        $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
        Http::fake();
    });

    it('shows each location\'s forecast in the product detail', function () {
        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)
            ->assertJsonPath('data.locations.0.location', 'Hanoi')
            ->assertJsonPath('data.locations.0.available', 40)
            ->assertJsonPath('data.locations.0.forecast.avg_daily_sales', 3)
            ->assertJsonPath('data.locations.1.location', 'Saigon')
            ->assertJsonPath('data.locations.1.forecast.explanation_sentences.0', 'Sells 1/day over the last 30 days.');
    });

    it('filters the product list by location and lists locations', function () {
        $this->getJson('/api/locations', $this->auth)->assertJsonPath('data.1.name', 'Saigon');
        $this->getJson("/api/forecasts?location_id={$this->saigon->id}", $this->auth)
            ->assertJsonPath('data.0.current_stock', 2)
            ->assertJsonPath('data.0.status', 'reorder_now');
    });

    it('exports a purchase order for one location', function () {
        $csv = $this->get("/api/purchase-orders/export?location_id={$this->saigon->id}", $this->auth)->assertOk()->streamedContent();

        expect($csv)->toContain('Saigon')->not->toContain('Hanoi');
    });
});
