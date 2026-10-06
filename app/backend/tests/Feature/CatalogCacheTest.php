<?php

use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Rarely changing reads are cached; every write that could change them bumps the
// shop's catalog (or forecast) version, so a cached value is never stale.

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'growth']);
    $this->location = Location::factory()->for($this->shop)->create(['name' => 'Hanoi']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
});

/** Queries against a table while running $fn. */
function queriesOn(string $table, callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = collect(DB::getQueryLog())->filter(fn ($q) => preg_match("/from [`\"]?{$table}[`\"]?/i", $q['query']))->count();
    DB::disableQueryLog();

    return $count;
}

it('caches the product list and refreshes it when forecasts or products change', function () {
    $list = fn () => $this->getJson('/api/forecasts?status=reorder_now', $this->auth)->assertOk();

    expect(queriesOn('forecasts', $list))->toBeGreaterThan(0)
        ->and(queriesOn('forecasts', $list))->toBe(0); // second time: cache

    // A sync that changes the product (here its vendor) shows at once.
    $mug = $this->mug->fresh();
    app(CatalogRepositoryInterface::class)->upsertVariants($this->shop, [[
        'shopify_variant_id' => $mug->shopify_variant_id, 'shopify_product_id' => $mug->shopify_product_id, 'inventory_item_id' => $mug->inventory_item_id,
        'product_title' => 'Mug', 'title' => 'Default Title', 'vendor' => 'Acme Ceramics', 'product_type' => null, 'sku' => $mug->sku,
        'unit_cost' => null, 'tracked' => true, 'is_active' => true, 'shopify_created_at' => '2025-01-01 00:00:00',
    ]]);
    $list()->assertJsonPath('data.0.vendor', 'Acme Ceramics');

    // New forecasts show at once.
    DB::table('inventory_levels')->update(['available' => 0]);
    app(ForecastService::class)->runForShop($this->shop->fresh());
    $list()->assertJsonPath('data.0.current_stock', 0);
});

it('does not cache searches', function () {
    $search = fn () => $this->getJson('/api/forecasts?search=mug', $this->auth)->assertOk()->assertJsonPath('meta.total', 1);

    $search();
    expect(queriesOn('forecasts', $search))->toBeGreaterThan(0);
});

it('caches the location list until a sync changes locations', function () {
    $locations = fn () => $this->getJson('/api/locations', $this->auth)->assertOk();

    $locations()->assertJsonPath('data', [['id' => $this->location->id, 'name' => 'Hanoi']]);
    expect(queriesOn('locations', $locations))->toBe(0);

    app(CatalogRepositoryInterface::class)->upsertLocations($this->shop, [
        ['shopify_location_id' => $this->location->shopify_location_id, 'name' => 'Hà Nội', 'is_active' => true],
    ]);
    $locations()->assertJsonPath('data.0.name', 'Hà Nội');
});

it('caches the bundle list until a bundle changes', function () {
    $bundles = fn () => $this->getJson('/api/bundles', $this->auth)->assertOk();
    $bundles()->assertJsonCount(0, 'data');
    expect(queriesOn('variants', $bundles))->toBe(0);

    $box = Variant::factory()->for($this->shop)->create(['product_title' => 'Gift box']);
    $this->postJson('/api/bundles', ['bundle' => $box->id, 'components' => [['variant' => $this->mug->id, 'quantity' => 2]]], $this->auth)->assertSuccessful();
    $bundles()->assertJsonCount(1, 'data')->assertJsonPath('data.0.components.0.quantity', 2);

    $this->deleteJson("/api/bundles/{$box->id}", [], $this->auth)->assertSuccessful();
    $bundles()->assertJsonCount(0, 'data');
});

it('counts tracked products with one cached COUNT query, refreshed after a sync', function () {
    $billing = fn () => $this->getJson('/api/billing', $this->auth)->assertOk();

    $billing()->assertJsonPath('data.usage.tracked_skus', 1);
    expect(queriesOn('variants', $billing))->toBe(0);

    $plate = Variant::factory()->for($this->shop)->create(['tracked' => false]);
    app(CatalogRepositoryInterface::class)->updateTracked($this->shop, [['variant_id' => $plate->id, 'tracked' => true]]);
    $billing()->assertJsonPath('data.usage.tracked_skus', 2);
});
