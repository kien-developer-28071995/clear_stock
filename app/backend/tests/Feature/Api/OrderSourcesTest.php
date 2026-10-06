<?php

use App\Jobs\Sync\StartSyncRun;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SyncRun;
use App\Services\Forecast\ForecastService;
use App\Services\Shopify\PurchaseOrderClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter',
        'last_synced_at' => now()->subDay(), 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => 14]);
    $this->bolt = Supplier::factory()->for($this->shop)->create(['name' => 'Bolt', 'lead_time_days' => 30]);
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 20, perDay: 4, attrs: ['supplier_id' => $this->acme->id, 'unit_cost' => 2, 'shopify_unit_cost' => 2, 'supplier_sku' => 'AC-1', 'inventory_item_id' => 9001]);
    app(ForecastService::class)->runForShop($this->shop);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('re-reads the order history when excluded orders change, and only then', function () {
    Http::fake();
    $this->putJson('/api/settings', ['excluded_order_tags' => [' Wholesale ', 'wholesale'], 'excluded_order_sources' => ['pos']], $this->auth)->assertOk()
        ->assertJsonPath('data.excluded_order_tags', ['Wholesale', 'wholesale'])->assertJsonPath('data.excluded_order_sources', ['pos']);
    Queue::assertPushed(StartSyncRun::class, 1);
    $run = SyncRun::query()->latest('id')->first();
    expect($run->window_start->toDateString())->toBe(now()->subDays(config('sync.initial_days'))->toDateString())   // the whole history window
        ->and($run->variants_updated_since)->toBeNull();

    SyncRun::query()->update(['status' => 'completed']);
    $this->putJson('/api/settings', ['excluded_order_tags' => ['wholesale', 'Wholesale'], 'default_lead_time_days' => 10], $this->auth)->assertOk();
    Queue::assertPushed(StartSyncRun::class, 1);   // same tags: no new sync

    $this->putJson('/api/settings', ['excluded_order_sources' => ['amazon']], $this->auth)->assertStatus(422);
});

it('keeps other suppliers of a product and switches the main one in one step', function () {
    Http::fake();
    $alternates = $this->putJson("/api/variants/{$this->mug->id}/suppliers", ['suppliers' => [
        ['supplier_id' => $this->bolt->id, 'unit_cost' => 1.5, 'lead_time_days' => 30, 'supplier_sku' => 'BO-9'],
        ['supplier_id' => 999999, 'unit_cost' => 1],   // not this shop's: dropped
    ]], $this->auth)->assertOk()->json('data');
    expect($alternates)->toBe([['supplier_id' => $this->bolt->id, 'name' => 'Bolt', 'unit_cost' => 1.5, 'lead_time_days' => 30, 'supplier_sku' => 'BO-9']]);
    expect($this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->json('data.alternate_suppliers.0.name'))->toBe('Bolt');
    $this->putJson("/api/variants/{$this->mug->id}/suppliers", ['suppliers' => [['supplier_id' => $this->acme->id]]], $this->auth)->assertStatus(422); // the main supplier

    $result = $this->postJson("/api/variants/{$this->mug->id}/suppliers/{$this->bolt->id}/main", [], $this->auth)->assertOk()->json('data');
    $mug = $this->mug->fresh();
    expect($result['supplier_id'])->toBe($this->bolt->id)
        ->and([$mug->lead_time_override, $mug->supplier_sku, (float) $mug->unit_cost])->toBe([30, 'BO-9', 1.5])
        // The supplier it replaced is kept with what the product had.
        ->and($result['alternates'])->toBe([['supplier_id' => $this->acme->id, 'name' => 'Acme', 'unit_cost' => null, 'lead_time_days' => null, 'supplier_sku' => 'AC-1']])
        ->and($mug->forecast->explanation['lead_time'])->toBe(['days' => 30, 'source' => 'variant']);

    $this->postJson("/api/variants/{$this->mug->id}/suppliers/{$this->bolt->id}/main", [], $this->auth)->assertStatus(422);
});

it('shows Shopify\'s ordered purchase orders once the scope is granted', function () {
    Http::fake(['*/admin/api/2026-10/graphql.json' => Http::response(['data' => ['inventoryPurchaseOrders' => ['nodes' => [
        ['id' => gid('InventoryPurchaseOrder', 11), 'name' => '#PO1', 'status' => 'ORDERED', 'orderedAt' => '2026-09-12T08:00:00Z', 'dateCreated' => '2026-09-10T08:00:00Z', 'currency' => 'USD', 'archivedAt' => null,
            'origin' => ['supplierName' => 'Acme'], 'lineItems' => ['nodes' => [
                ['title' => 'Mug', 'variantTitle' => 'Default Title', 'supplierSku' => 'AC-1', 'totalQuantity' => 100, 'unitCost' => ['amount' => '2.00'], 'inventoryItem' => ['id' => gid('InventoryItem', 9001)]],
                ['title' => 'Unknown thing', 'variantTitle' => 'Red', 'supplierSku' => null, 'totalQuantity' => 5, 'unitCost' => null, 'inventoryItem' => null],
            ]]],
        ['id' => gid('InventoryPurchaseOrder', 12), 'name' => '#PO2', 'status' => 'DRAFT', 'orderedAt' => null, 'dateCreated' => '2026-09-15T08:00:00Z', 'currency' => 'USD', 'archivedAt' => null, 'origin' => null, 'lineItems' => ['nodes' => []]],
    ]]]])]);

    $this->getJson('/api/shopify-purchase-orders', $this->auth)->assertOk()
        ->assertJsonPath('data.scope_granted', false)->assertJsonPath('data.scope', PurchaseOrderClient::SCOPE)->assertJsonPath('data.orders', []);
    Http::assertNothingSent();

    $this->shop->update(['scopes' => 'read_products,read_inventory_purchase_orders']);
    $data = $this->getJson('/api/shopify-purchase-orders', $this->auth)->assertOk()->json('data');
    expect($data['orders'])->toHaveCount(1)       // drafts are not on the way
        ->and($data['orders'][0])->toMatchArray(['name' => '#PO1', 'supplier' => 'Acme', 'ordered_on' => '2026-09-12', 'units' => 105, 'cost' => 200.0])
        ->and($data['orders'][0]['lines'])->toBe([
            ['variant_id' => $this->mug->id, 'name' => 'Mug', 'supplier_sku' => 'AC-1', 'quantity' => 100],
            ['variant_id' => null, 'name' => 'Unknown thing - Red', 'supplier_sku' => null, 'quantity' => 5],
        ]);
});

it('reports a Shopify error instead of failing, follows the plan and the switch', function () {
    $this->shop->update(['scopes' => 'read_inventory_purchase_orders']);
    Http::fake(['*' => Http::response(['errors' => [['message' => 'Access denied']]])]);
    $this->getJson('/api/shopify-purchase-orders', $this->auth)->assertOk()->assertJsonPath('data.error', 'shopify_error')->assertJsonPath('data.orders', []);

    $this->shop->update(['plan' => 'free']);
    $this->getJson('/api/shopify-purchase-orders', $this->auth)->assertStatus(402);
    $this->shop->update(['plan' => 'starter']);
    config(['features.shopify_purchase_orders' => false]);
    $this->getJson('/api/shopify-purchase-orders', $this->auth)->assertNotFound();
});
