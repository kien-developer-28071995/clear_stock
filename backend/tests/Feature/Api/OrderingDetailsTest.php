<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\ManualOrder;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create(['name' => 'Shop']);
    $this->acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => 14]);
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 20, perDay: 4, attrs: ['sku' => 'MUG', 'supplier_id' => $this->acme->id, 'unit_cost' => 2, 'vendor' => 'Acme Co']);
    app(ForecastService::class)->runForShop($this->shop);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('keeps the rest of a partly delivered order on the way, and receives it with the last units', function () {
    $this->postJson('/api/manual-orders', ['items' => [['variant_id' => $this->mug->id, 'quantity' => 100]]], $this->auth)->assertCreated();
    $order = ManualOrder::first();
    expect($this->mug->forecast()->first()->incoming_stock)->toBe(100);

    $this->patchJson("/api/manual-orders/{$order->id}", ['received_quantity' => 40], $this->auth)->assertOk()
        ->assertJsonPath('data.state', 'open')->assertJsonPath('data.received_quantity', 40);
    expect($this->mug->forecast()->first()->incoming_stock)->toBe(60);

    // More than ordered is capped; the last units close the order.
    $this->patchJson("/api/manual-orders/{$order->id}", ['received_quantity' => 150], $this->auth)->assertOk()
        ->assertJsonPath('data.state', 'received')->assertJsonPath('data.received_quantity', 100);
    expect($this->mug->forecast()->first()->incoming_stock)->toBe(0)->and($order->fresh()->closed_at)->not->toBeNull();
});

it('marks an order received in one go as fully received', function () {
    $order = ManualOrder::factory()->for($this->shop)->create(['variant_id' => $this->mug->id, 'quantity' => 30]);
    $this->patchJson("/api/manual-orders/{$order->id}", ['status' => 'received'], $this->auth)->assertOk()->assertJsonPath('data.received_quantity', 30);
});

it('reports how long a supplier\'s deliveries really took, once there are enough', function () {
    $received = fn (string $ordered, string $closed) => ManualOrder::factory()->for($this->shop)->create([
        'variant_id' => $this->mug->id, 'supplier_id' => $this->acme->id, 'ordered_on' => $ordered, 'status' => 'received', 'closed_at' => $closed.' 12:00:00', 'received_quantity' => 10, 'quantity' => 10,
    ]);
    $received('2026-06-01', '2026-06-19'); // 18 days
    $received('2026-07-01', '2026-07-21'); // 20 days
    expect($this->getJson('/api/suppliers', $this->auth)->json('data.0.actual_lead_time'))->toBeNull(); // two say too little

    $received('2026-08-01', '2026-08-26'); // 25 days
    ManualOrder::factory()->for($this->shop)->create(['variant_id' => $this->mug->id, 'supplier_id' => $this->acme->id, 'ordered_on' => '2026-09-01', 'status' => 'cancelled', 'closed_at' => now()]);
    $received('2025-01-01', '2025-03-01'); // over a year ago: ignored

    expect($this->getJson('/api/suppliers', $this->auth)->json('data.0.actual_lead_time'))->toBe(['median_days' => 20, 'orders' => 3]);
});

it('puts the supplier\'s own product code on purchase orders', function () {
    $this->putJson("/api/variants/{$this->mug->id}/settings", ['supplier_sku' => 'AC-778'], $this->auth)->assertOk()->assertJsonPath('data.supplier_sku', 'AC-778');
    expect($this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->json('data.settings.supplier_sku'))->toBe('AC-778');

    $csv = $this->get('/api/purchase-orders/export?format=shopify', $this->auth)->assertOk()->streamedContent();
    expect($csv)->toContain("SKU,Barcode,Supplier SKU,Quantity,Cost,Tax\n")->toContain('MUG,')->toContain(',AC-778,');
    expect($this->get('/api/purchase-orders/export', $this->auth)->streamedContent())->toContain('"Supplier SKU"')->toContain('AC-778');
});

it('leaves the stock of an excluded location out of the forecast', function () {
    $returns = Location::factory()->for($this->shop)->create(['name' => 'Returns']);
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $this->mug->id, 'location_id' => $returns->id, 'available' => 500]);
    app(ForecastService::class)->runForShop($this->shop);
    expect($this->mug->forecast()->first()->current_stock)->toBe(520);

    $this->putJson('/api/settings/locations', ['excluded_ids' => [$returns->id]], $this->auth)->assertOk()
        ->assertJsonPath('data', [['id' => $returns->id, 'name' => 'Returns', 'excluded' => true], ['id' => $this->location->id, 'name' => 'Shop', 'excluded' => false]]);
    Queue::assertPushed(RecomputeForecasts::class);
    app(ForecastService::class)->runForShop($this->shop);
    expect($this->mug->forecast()->first()->current_stock)->toBe(20);

    // Not every location, and never another shop's.
    $this->putJson('/api/settings/locations', ['excluded_ids' => [$returns->id, $this->location->id]], $this->auth)->assertStatus(422);
    $other = Location::factory()->for(Shop::factory()->create())->create();
    $this->putJson('/api/settings/locations', ['excluded_ids' => [$other->id]], $this->auth)->assertOk();
    expect($other->fresh()->excluded)->toBeFalse()->and($returns->fresh()->excluded)->toBeFalse();
});

it('exports the product list with the current filters as CSV, on every plan', function () {
    $this->shop->update(['plan' => 'free']);
    product($this->shop, $this->location, 'Plate', stock: 5000, perDay: 1, attrs: ['sku' => 'PLATE', 'unit_cost' => 3]);
    app(ForecastService::class)->runForShop($this->shop->fresh());

    $rows = array_map('str_getcsv', array_filter(explode("\n", substr($this->get('/api/forecasts/export?sort=name', $this->auth)->assertOk()->streamedContent(), 3))));
    expect($rows[0])->toContain('Product', 'Sells per day', 'Stock value')
        ->and(array_column($rows, 0))->toBe(['Product', 'Mug', 'Plate'])
        ->and(array_slice($rows[1], 0, 9))->toBe(['Mug', 'MUG', 'Acme Co', 'Acme', 'reorder_now', $rows[1][5], '20', '0', '4'])
        ->and($rows[2][15])->toBe('15000');

    $only = array_filter(explode("\n", $this->get('/api/forecasts/export?status=slow', $this->auth)->streamedContent()));
    expect($only)->toHaveCount(2)->and($only[1])->toContain('Plate');
});

it('explains units sold on backorder', function () {
    InventoryLevel::query()->where('variant_id', $this->mug->id)->update(['available' => -6]);
    app(ForecastService::class)->runForShop($this->shop);

    $detail = $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->json('data');
    expect($detail['suggested_qty'])->toBe(210)   // 4 x (14 + 7 + 30) + 6 already sold
        ->and(collect($detail['explanation_lines'])->firstWhere('code', 'backorder_included')['params'])->toBe(['count' => 6]);
});
