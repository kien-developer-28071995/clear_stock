<?php

use App\Models\Forecast;
use App\Models\InventoryTransfer;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Growth: stock transfer suggestions between locations, created as draft transfers in Shopify.

const TRANSFER_SCOPES = 'read_products,read_inventory,read_locations,read_orders,read_all_orders,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders';

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'growth', 'scopes' => TRANSFER_SCOPES.',write_inventory_transfers']);
    $this->store = Location::factory()->for($this->shop)->create(['shopify_location_id' => 11, 'name' => 'Store']);
    $this->warehouse = Location::factory()->for($this->shop)->create(['shopify_location_id' => 22, 'name' => 'Warehouse']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    $this->mug = Variant::factory()->for($this->shop)->create(['product_title' => 'Mug', 'title' => 'Default Title', 'sku' => 'MUG', 'inventory_item_id' => 9001]);
    // Store is low (5 <= reorder point 20, keeps 40); warehouse holds 100 and keeps 30.
    $this->locationForecast = fn (Variant $v, Location $l, int $stock, float $perDay, int $reorderPoint, int $target, ?string $stockout = null) => Forecast::factory()->create([
        'variant_id' => $v->id, 'location_id' => $l->id, 'current_stock' => $stock, 'incoming_stock' => 0, 'avg_daily_sales' => $perDay,
        'reorder_point' => $reorderPoint, 'target_stock' => $target, 'stockout_date' => $stockout, 'days_of_cover' => $perDay > 0 ? $stock / $perDay : null,
    ]);
    ($this->locationForecast)($this->mug, $this->store, 5, 2, 20, 40, '2026-09-22');
    ($this->locationForecast)($this->mug, $this->warehouse, 100, 1, 15, 30);
});

it('suggests moving spare stock to the location that runs low, grouped per route', function () {
    $this->getJson('/api/transfers', $this->auth)
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.scope_granted', true)
        ->assertJsonPath('data.routes.0.origin', ['id' => $this->warehouse->id, 'name' => 'Warehouse'])
        ->assertJsonPath('data.routes.0.destination', ['id' => $this->store->id, 'name' => 'Store'])
        ->assertJsonPath('data.routes.0.total_units', 35)
        ->assertJsonPath('data.routes.0.items.0', [
            'variant_id' => $this->mug->id, 'name' => 'Mug', 'sku' => 'MUG', 'quantity' => 35,
            'origin_stock' => 100, 'destination_stock' => 5, 'destination_days_of_cover' => 2.5, 'destination_stockout_date' => '2026-09-22',
        ]);
});

it('is a Growth feature, and needs forecasts per location', function () {
    $this->shop->update(['plan' => 'starter']);
    $this->getJson('/api/transfers', $this->auth)->assertStatus(402)->assertJsonPath('params.plan', 'growth');

    $this->shop->update(['plan' => 'growth']);
    $this->warehouse->update(['is_active' => false]); // one active location left
    $this->getJson('/api/transfers', $this->auth)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.routes', []);
});

it('creates a draft transfer in Shopify and stops suggesting what it moves', function () {
    Http::fake(['*.myshopify.com/*' => Http::response(['data' => ['inventoryTransferCreate' => [
        'inventoryTransfer' => ['id' => 'gid://shopify/InventoryTransfer/555', 'name' => '#T0001', 'status' => 'DRAFT'], 'userErrors' => [],
    ]]])]);

    $this->postJson('/api/transfers', [
        'origin_location_id' => $this->warehouse->id,
        'destination_location_id' => $this->store->id,
        'items' => [['variant_id' => $this->mug->id, 'quantity' => 30]], // edited down from 35
        'idempotency_key' => 'b2a6c1f0-1d2e-4f3a-9b8c-7d6e5f4a3b2c',
    ], $this->auth)->assertCreated()->assertJsonPath('data.name', '#T0001')->assertJsonPath('data.shopify_transfer_id', 555);

    Http::assertSent(function (Request $r) {
        $body = $r->data();

        return str_contains($body['query'], '@idempotent(key: $idempotencyKey)')
            && $body['variables']['idempotencyKey'] === 'b2a6c1f0-1d2e-4f3a-9b8c-7d6e5f4a3b2c'
            && $body['variables']['input']['originLocationId'] === 'gid://shopify/Location/22'
            && $body['variables']['input']['destinationLocationId'] === 'gid://shopify/Location/11'
            && $body['variables']['input']['lineItems'] === [['inventoryItemId' => 'gid://shopify/InventoryItem/9001', 'quantity' => 30]];
    });

    // 30 of the 35 are in the draft: only 5 left to suggest, and the draft is listed.
    $this->getJson('/api/transfers', $this->auth)
        ->assertJsonPath('data.routes.0.items.0.quantity', 5)
        ->assertJsonPath('data.recent.0.name', '#T0001')
        ->assertJsonPath('data.recent.0.origin', 'Warehouse')
        ->assertJsonPath('data.recent.0.total_units', 30);

    // After a week the draft no longer counts (it has been shipped, or abandoned).
    InventoryTransfer::query()->update(['created_at' => now()->subDays(8)]);
    $this->getJson('/api/transfers', $this->auth)->assertJsonPath('data.recent', [])->assertJsonPath('data.routes.0.items.0.quantity', 35);
});

it('asks for the transfer permission when the merchant has not granted it yet', function () {
    $this->shop->update(['scopes' => TRANSFER_SCOPES]);
    Http::fake(['*.myshopify.com/*' => Http::response(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_products']]]]])]);

    $this->getJson('/api/transfers', $this->auth)->assertJsonPath('data.scope_granted', false);
    $this->postJson('/api/transfers', [
        'origin_location_id' => $this->warehouse->id, 'destination_location_id' => $this->store->id,
        'items' => [['variant_id' => $this->mug->id, 'quantity' => 5]], 'idempotency_key' => str_repeat('a', 32),
    ], $this->auth)->assertForbidden()->assertJsonPath('code', 'scope_required')->assertJsonPath('params.scope', 'write_inventory_transfers');

    expect(InventoryTransfer::count())->toBe(0);
});

it('picks up a permission granted in the app before the webhook arrives', function () {
    $this->shop->update(['scopes' => TRANSFER_SCOPES]);
    Http::fakeSequence('*.myshopify.com/*')
        ->push(['data' => ['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_products'], ['handle' => 'write_inventory_transfers']]]]])
        ->push(['data' => ['inventoryTransferCreate' => ['inventoryTransfer' => ['id' => 'gid://shopify/InventoryTransfer/9', 'name' => '#T0009', 'status' => 'DRAFT'], 'userErrors' => []]]]);

    $this->postJson('/api/transfers', [
        'origin_location_id' => $this->warehouse->id, 'destination_location_id' => $this->store->id,
        'items' => [['variant_id' => $this->mug->id, 'quantity' => 5]], 'idempotency_key' => str_repeat('b', 32),
    ], $this->auth)->assertCreated();

    expect($this->shop->fresh()->scopes)->toContain('write_inventory_transfers');
});

it('rejects locations and products of another shop, and reports Shopify refusing the transfer', function () {
    $other = Location::factory()->for(Shop::factory())->create();
    $foreignVariant = Variant::factory()->for(Shop::factory())->create();
    $payload = fn (array $overrides) => $overrides + [
        'origin_location_id' => $this->warehouse->id, 'destination_location_id' => $this->store->id,
        'items' => [['variant_id' => $this->mug->id, 'quantity' => 5]], 'idempotency_key' => str_repeat('c', 32),
    ];

    $this->postJson('/api/transfers', $payload(['origin_location_id' => $other->id]), $this->auth)->assertUnprocessable()->assertJsonPath('code', 'invalid_location');
    $this->postJson('/api/transfers', $payload(['destination_location_id' => $this->warehouse->id]), $this->auth)->assertUnprocessable();
    $this->postJson('/api/transfers', $payload(['items' => [['variant_id' => $foreignVariant->id, 'quantity' => 1]]]), $this->auth)->assertUnprocessable()->assertJsonPath('code', 'invalid_product');

    Http::fake(['*.myshopify.com/*' => Http::response(['data' => ['inventoryTransferCreate' => ['inventoryTransfer' => null, 'userErrors' => [['field' => ['input'], 'message' => 'Location is not active']]]]])]);
    $this->postJson('/api/transfers', $payload([]), $this->auth)->assertUnprocessable()->assertJsonPath('code', 'transfer_rejected');
    expect(InventoryTransfer::count())->toBe(0);
});
