<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Shopify admin extensions: forecast block on the product page, bulk settings on the product list.

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'starter']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    // One Shopify product (id 700) with two variants, one of them not tracked.
    $this->small = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['shopify_product_id' => 700, 'title' => 'Small', 'sku' => 'MUG-S']);
    $this->large = Variant::factory()->for($this->shop)->create(['shopify_product_id' => 700, 'product_title' => 'Mug', 'title' => 'Large', 'tracked' => false]);
    $this->other = product($this->shop, $this->location, 'Plate', stock: 50, perDay: 1, attrs: ['shopify_product_id' => 800]);
    app(ForecastService::class)->runForShop($this->shop);
});

it('returns each variant of a Shopify product with its forecast and explanation', function () {
    $this->getJson('/api/extension/products/700', $this->auth)
        ->assertOk()
        ->assertJsonPath('data.synced', true)
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.variants.0.title', 'Small')
        ->assertJsonPath('data.variants.0.sku', 'MUG-S')
        ->assertJsonPath('data.variants.0.forecast.status', 'reorder_now')
        ->assertJsonPath('data.variants.0.forecast.current_stock', 10)
        ->assertJsonPath('data.variants.0.forecast.avg_daily_sales', 4)
        ->assertJsonPath('data.variants.0.forecast.explanation_lines.0.code', 'sells_over_window')
        ->assertJsonPath('data.variants.0.not_forecast_reason', null)
        ->assertJsonPath('data.variants.1.forecast', null)
        ->assertJsonPath('data.variants.1.not_forecast_reason', 'not_tracked');
});

it('says when a product is beyond the free plan limit or not synced yet', function () {
    config(['billing.plans.free.limits.max_skus' => 1]);
    $this->shop->update(['plan' => 'free']);
    app(ForecastService::class)->runForShop($this->shop); // only the best seller (Mug) keeps a forecast

    $this->getJson('/api/extension/products/800', $this->auth)->assertJsonPath('data.variants.0.not_forecast_reason', 'plan_limit');
    $this->getJson('/api/extension/products/999', $this->auth)->assertOk()->assertJsonPath('data.synced', false)->assertJsonPath('data.variants', []);
});

it('needs a Shopify ID token, like every API route', function () {
    $this->getJson('/api/extension/products/700')->assertUnauthorized();
});

it('allows cross-origin calls from Shopify\'s extension domain', function () {
    $this->call('OPTIONS', '/api/extension/products/700', server: [
        'HTTP_ORIGIN' => 'https://extensions.shopifycdn.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
});

it('sets supplier and lead time on every variant of the selected products', function () {
    $acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme']);

    $this->postJson('/api/extension/product-settings', [
        'product_ids' => ['gid://shopify/Product/700'],
        'supplier_id' => $acme->id,
        'lead_time_override' => 21,
    ], $this->auth)->assertOk()->assertJsonPath('data.updated', 2);

    expect($this->small->fresh()->only(['supplier_id', 'lead_time_override']))->toBe(['supplier_id' => $acme->id, 'lead_time_override' => 21])
        ->and($this->large->fresh()->supplier_id)->toBe($acme->id)
        ->and($this->other->fresh()->supplier_id)->toBeNull();
    Queue::assertPushed(RecomputeForecasts::class);
});

it('rejects bad product ids and suppliers of another shop, and ignores products it does not know', function () {
    $foreign = Supplier::factory()->for(Shop::factory())->create();

    $this->postJson('/api/extension/product-settings', ['product_ids' => ['123'], 'lead_time_override' => 5], $this->auth)
        ->assertUnprocessable()->assertJsonPath('errors', ['product_ids.0' => [['code' => 'regex', 'params' => []]]]);
    $this->postJson('/api/extension/product-settings', ['product_ids' => ['gid://shopify/Product/700'], 'supplier_id' => $foreign->id], $this->auth)
        ->assertUnprocessable();
    $this->postJson('/api/extension/product-settings', ['product_ids' => ['gid://shopify/Product/999'], 'lead_time_override' => 5], $this->auth)
        ->assertOk()->assertJsonPath('data.updated', 0);
});

it('returns one variant for the variant page, with what is already marked as ordered', function () {
    $this->getJson("/api/extension/variants/{$this->small->shopify_variant_id}", $this->auth)->assertOk()
        ->assertJsonPath('data.synced', true)
        ->assertJsonCount(1, 'data.variants')
        ->assertJsonPath('data.variants.0.variant_id', $this->small->id)
        ->assertJsonPath('data.variants.0.ordered', null);
    $this->getJson('/api/extension/variants/999999', $this->auth)->assertOk()->assertJsonPath('data.synced', false);

    $this->postJson('/api/extension/manual-orders', ['items' => [['variant_id' => $this->small->id, 'quantity' => 150]], 'reference' => 'PO-9'], $this->auth)
        ->assertCreated()->assertJsonPath('data.recorded', 1);

    $this->getJson('/api/extension/products/700', $this->auth)
        ->assertJsonPath('data.variants.0.ordered.units', 150)
        ->assertJsonPath('data.variants.0.ordered.expected_on', '2026-10-04')
        ->assertJsonPath('data.variants.0.forecast.incoming_stock', 150);
});

it('sets minimum order, pack size and discontinued from the product page action', function () {
    $this->postJson('/api/extension/product-settings', ['product_ids' => ['gid://shopify/Product/700'], 'min_order_qty' => 24, 'pack_size' => 12, 'discontinued' => true], $this->auth)
        ->assertOk()->assertJsonPath('data.updated', 2);

    expect($this->small->fresh())->min_order_qty->toBe(24)->pack_size->toBe(12)->discontinued->toBeTrue()
        ->and($this->other->fresh()->discontinued)->toBeFalse();
    $this->postJson('/api/extension/product-settings', ['product_ids' => ['gid://shopify/Product/700'], 'pack_size' => 0], $this->auth)->assertUnprocessable();
});
