<?php

use App\Enums\Confidence;
use App\Events\ForecastsUpdated;
use App\Events\ShopSynced;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\BundleComponent;
use App\Models\DailySale;
use App\Models\Forecast;
use App\Models\ForecastOverride;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SyncRun;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-09-20 08:00:00', 'UTC');
    $this->shop = Shop::factory()->create(['timezone' => 'UTC', 'default_lead_time_days' => 14, 'default_safety_days' => 7]);
    $this->location = Location::factory()->for($this->shop)->create();
});

/** Variant with stock and N days of history at $perDay units (optionally out of stock for some days). */
function sellingVariant(Shop $shop, Location $loc, int $stock, int $perDay, int $days = 120, array $attrs = [], array $oosDaysAgo = []): Variant
{
    $v = Variant::factory()->for($shop)->create($attrs + ['shopify_created_at' => '2025-01-01 00:00:00']);
    InventoryLevel::factory()->create(['shop_id' => $shop->id, 'variant_id' => $v->id, 'location_id' => $loc->id, 'available' => $stock]);

    $rows = [];
    for ($ago = 1; $ago <= $days; $ago++) {
        $oos = in_array($ago, $oosDaysAgo, true);
        $rows[] = ['shop_id' => $shop->id, 'variant_id' => $v->id, 'date' => CarbonImmutable::parse('2026-09-20')->subDays($ago)->toDateString(),
            'units_sold' => $oos ? 0 : $perDay, 'units_returned' => 0, 'end_of_day_stock' => null, 'was_in_stock' => ! $oos];
    }
    DailySale::insert($rows);

    return $v;
}

function forecastOf(Variant $v): Forecast
{
    return Forecast::query()->where('variant_id', $v->id)->whereNull('location_id')->firstOrFail();
}

it('computes and stores a forecast with its explanation for each tracked variant', function () {
    Event::fake([ForecastsUpdated::class]);
    $v = sellingVariant($this->shop, $this->location, stock: 100, perDay: 4, oosDaysAgo: [3, 4, 5]);

    $stats = app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    $f = forecastOf($v);
    expect($f->avg_daily_sales)->toBe('4.000')
        ->and($f->current_stock)->toBe(100)
        ->and($f->reorder_point)->toBe(84)
        ->and($f->stockout_date->toDateString())->toBe('2026-10-15')
        ->and($f->confidence)->toBe(Confidence::High)
        ->and(collect($f->explanation['windows'])->firstWhere('days', 30)['excluded_out_of_stock_days'])->toBe(3)
        ->and($stats['variants'])->toBe(1)
        ->and($this->shop->fresh()->forecasted_at)->not->toBeNull();
    Event::assertDispatched(ForecastsUpdated::class);
});

it('applies supplier lead time, variant safety days and active overrides', function () {
    $supplier = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => 30]);
    $v = sellingVariant($this->shop, $this->location, stock: 100, perDay: 2, attrs: ['supplier_id' => $supplier->id, 'safety_days' => 10]);
    ForecastOverride::factory()->create(['variant_id' => $v->id, 'field' => 'avg_daily_sales', 'value' => 5, 'note' => 'Black Friday']);
    ForecastOverride::factory()->create(['variant_id' => $v->id, 'field' => 'lead_time_days', 'value' => 20, 'expires_at' => now()->subDay()]); // expired

    app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    $e = forecastOf($v)->explanation;
    expect($e['avg_daily_sales'])->toEqual(5)
        ->and($e['avg_source'])->toBe('override')
        ->and($e['lead_time'])->toBe(['days' => 30, 'source' => 'supplier', 'supplier' => 'Acme'])
        ->and($e['safety']['source'])->toBe('variant')
        ->and(forecastOf($v)->reorder_point)->toBe(200); // 5 x (30 + 10)
});

it('adds demand from manual bundles but not from native Shopify bundles', function () {
    $this->shop->update(['plan' => 'starter']);
    $component = sellingVariant($this->shop, $this->location, stock: 200, perDay: 2);
    $manual = sellingVariant($this->shop, $this->location, stock: 0, perDay: 1, attrs: ['is_bundle' => true, 'tracked' => false]);
    $native = sellingVariant($this->shop, $this->location, stock: 0, perDay: 5, attrs: ['is_bundle' => true, 'tracked' => false]);
    BundleComponent::create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $manual->id, 'component_variant_id' => $component->id, 'quantity' => 2, 'source' => 'manual']);
    BundleComponent::create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $native->id, 'component_variant_id' => $component->id, 'quantity' => 1, 'source' => 'shopify']);

    app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    $f = forecastOf($component);
    expect((float) $f->avg_daily_sales)->toBe(4.0) // 2 own + 1 manual bundle x 2
        ->and($f->explanation['bundles'])->toHaveCount(1)
        ->and($f->explanation['bundles'][0]['name'])->toBe($manual->displayName())
        // Untracked bundles themselves get no stock forecast.
        ->and(Forecast::where('variant_id', $manual->id)->exists())->toBeFalse();
});

it('ignores manual bundle demand on the Free plan', function () {
    $component = sellingVariant($this->shop, $this->location, stock: 200, perDay: 2);
    $bundle = sellingVariant($this->shop, $this->location, stock: 0, perDay: 1, attrs: ['is_bundle' => true, 'tracked' => false]);
    BundleComponent::create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id, 'quantity' => 2, 'source' => 'manual']);

    app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    expect((float) forecastOf($component)->avg_daily_sales)->toBe(2.0)
        ->and(forecastOf($component)->explanation['bundles'])->toBe([]);
});

it('forecasts only the best sellers up to the Free plan limit', function () {
    config(['billing.plans.free.limits.max_skus' => 2]);
    $slow = sellingVariant($this->shop, $this->location, stock: 10, perDay: 1);
    $fast = sellingVariant($this->shop, $this->location, stock: 10, perDay: 9);
    $mid = sellingVariant($this->shop, $this->location, stock: 10, perDay: 5);

    $stats = app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    expect(Forecast::pluck('variant_id')->sort()->values()->all())->toBe(collect([$fast->id, $mid->id])->sort()->values()->all())
        ->and($stats['not_forecasted'])->toBe(1);

    // Upgrading lifts the limit.
    $this->shop->update(['plan' => 'starter']);
    app(ForecastService::class)->runForShop($this->shop->fresh(), now: $this->now);
    expect(Forecast::where('variant_id', $slow->id)->exists())->toBeTrue();
});

it('gives low confidence to a variant with little history', function () {
    $v = sellingVariant($this->shop, $this->location, stock: 10, perDay: 1, days: 6, attrs: ['shopify_created_at' => '2026-09-14 00:00:00']);

    app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    expect(forecastOf($v)->confidence)->toBe(Confidence::Low)
        ->and(forecastOf($v)->explanation['history']['days_available'])->toBe(6);
});

it('removes forecasts of variants that are no longer active or tracked', function () {
    $keep = sellingVariant($this->shop, $this->location, stock: 10, perDay: 1);
    $gone = sellingVariant($this->shop, $this->location, stock: 10, perDay: 1);
    app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    $gone->update(['is_active' => false]);
    $stats = app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    expect(Forecast::where('variant_id', $gone->id)->exists())->toBeFalse()
        ->and(Forecast::where('variant_id', $keep->id)->count())->toBe(1)
        ->and($stats['removed'])->toBe(1);
});

it('can recompute only some variants', function () {
    $a = sellingVariant($this->shop, $this->location, stock: 10, perDay: 1);
    $b = sellingVariant($this->shop, $this->location, stock: 10, perDay: 1);

    app(ForecastService::class)->runForShop($this->shop, [$a->id], now: $this->now);

    expect(Forecast::where('variant_id', $a->id)->exists())->toBeTrue()
        ->and(Forecast::where('variant_id', $b->id)->exists())->toBeFalse();
});

it('never mixes shops', function () {
    $other = Shop::factory()->create();
    $otherVariant = sellingVariant($other, Location::factory()->for($other)->create(), stock: 5, perDay: 9);
    sellingVariant($this->shop, $this->location, stock: 10, perDay: 1);

    app(ForecastService::class)->runForShop($this->shop, now: $this->now);

    expect(Forecast::where('variant_id', $otherVariant->id)->exists())->toBeFalse();
});

it('recomputes forecasts after every successful sync', function () {
    Queue::fake();
    $run = SyncRun::create(['shop_id' => $this->shop->id, 'type' => 'nightly', 'status' => 'completed', 'stage' => 'completed', 'window_start' => '2026-08-20', 'started_at' => now()]);

    ShopSynced::dispatch($this->shop, $run);

    Queue::assertPushed(RecomputeForecasts::class, fn ($job) => $job->shopId === $this->shop->id && $job->variantIds === null);
});

it('recomputes stale forecasts at the nightly forecast hour (shop time)', function () {
    Queue::fake();
    $this->travelTo('2026-09-20 09:10:00'); // 05:10 in New York, 18:10 in Tokyo
    $ny = Shop::factory()->create(['timezone' => 'America/New_York', 'forecasted_at' => now()->subDay()]);
    Shop::factory()->create(['timezone' => 'America/New_York', 'forecasted_at' => now()->subHours(2)]); // fresh
    Shop::factory()->create(['timezone' => 'Asia/Tokyo', 'forecasted_at' => null]);

    $this->artisan('forecast:nightly')->assertSuccessful();

    Queue::assertPushed(RecomputeForecasts::class, 1);
    Queue::assertPushed(RecomputeForecasts::class, fn ($job) => $job->shopId === $ny->id);
});
