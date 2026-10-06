<?php

use App\Models\DailySale;
use App\Models\Forecast;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'growth', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create(['name' => 'Shop']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('lists slow and overstocked products with sell-through and the last sale, and exports them', function () {
    $fast = product($this->shop, $this->location, 'Fast', stock: 60, perDay: 4, attrs: ['unit_cost' => 1]);
    $slow = product($this->shop, $this->location, 'Slow', stock: 900, perDay: 1, attrs: ['unit_cost' => 2, 'sku' => 'SLOW']);   // 900 days of stock
    $dead = product($this->shop, $this->location, 'Dead', stock: 50, perDay: 0, attrs: ['unit_cost' => 10]);
    DailySale::query()->where('variant_id', $dead->id)->where('date', '2026-06-01')->update(['units_sold' => 3]);
    app(ForecastService::class)->runForShop($this->shop);

    $data = $this->getJson('/api/clearance', $this->auth)->assertOk()->json('data');
    expect(array_column($data['items'], 'name'))->toBe(['Slow', 'Dead'])
        ->and($data['value'])->toEqual(2300)
        ->and($data['items'][0])->toMatchArray(['status' => 'slow', 'stock' => 900, 'sold' => 90, 'sell_through' => 0.091, 'last_sold_on' => '2026-09-19', 'days_since_sale' => 1])
        ->and($data['items'][1])->toMatchArray(['sold' => 0, 'last_sold_on' => '2026-06-01', 'days_since_sale' => 111]);

    $csv = $this->get('/api/clearance/export', $this->auth)->assertOk()->streamedContent();
    expect($csv)->toContain('Sell-through %')->toContain('Slow,SLOW,slow,900,900,90,9.1,2026-09-19')->not->toContain('Fast');
});

it('finds products whose best-selling variant is short while others sit', function () {
    $tee = fn (string $size, int $stock, int $perDay) => product($this->shop, $this->location, 'Tee', stock: $stock, perDay: $perDay, attrs: ['title' => $size, 'shopify_product_id' => 777]);
    $tee('M', 0, 6);       // key seller, out of stock
    $tee('L', 400, 3);
    $tee('XS', 300, 1);    // 300 days of stock: sitting
    product($this->shop, $this->location, 'Mug', stock: 0, perDay: 4);     // single variant: not a size run
    app(ForecastService::class)->runForShop($this->shop);

    $runs = $this->getJson('/api/size-runs', $this->auth)->assertOk()->json('data');
    expect($runs)->toHaveCount(1)
        ->and($runs[0])->toMatchArray(['product' => 'Tee', 'short' => 1, 'sitting' => 2])
        ->and(array_column($runs[0]['variants'], 'state', 'title'))->toBe(['M' => 'short', 'L' => 'sitting', 'XS' => 'sitting'])
        ->and($runs[0]['variants'][0]['share'])->toBe(0.6);
});

it('saves, replaces and deletes product list views', function () {
    $views = $this->postJson('/api/views', ['name' => 'A class rising', 'filters' => ['abc' => 'A', 'trend' => 'up', 'page' => 3, 'search' => 'x', 'vendor' => '']], $this->auth)->assertCreated()->json('data');
    expect($views)->toHaveCount(1)->and($views[0]['filters'])->toBe(['abc' => 'A', 'trend' => 'up']);

    $this->postJson('/api/views', ['name' => 'A class rising', 'filters' => ['abc' => 'B']], $this->auth)->assertCreated()->assertJsonCount(1, 'data')->assertJsonPath('data.0.filters.abc', 'B');
    $other = Shop::factory()->create(['domain' => 'other.myshopify.com']);
    $this->postJson('/api/views', ['name' => 'Theirs', 'filters' => []], ['Authorization' => 'Bearer '.sessionToken('other.myshopify.com')])->assertCreated();
    expect($this->getJson('/api/views', $this->auth)->json('data'))->toHaveCount(1);

    $this->deleteJson("/api/views/{$views[0]['id']}", [], $this->auth)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/views', ['name' => '', 'filters' => []], $this->auth)->assertStatus(422);
});

it('uses a manual minimum per location for that location\'s forecast (Growth)', function () {
    $this->shop->update(['scopes' => 'read_products,read_inventory,read_locations,read_orders,read_all_orders,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders']);
    $second = Location::factory()->for($this->shop)->create(['name' => 'Warehouse']);
    $mug = product($this->shop, $this->location, 'Mug', stock: 500, perDay: 4);
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $mug->id, 'location_id' => $second->id, 'available' => 40]);
    app(ForecastService::class)->runForShop($this->shop->fresh());
    $at = fn () => Forecast::withoutGlobalScopes()->where('variant_id', $mug->id)->where('location_id', $second->id)->first();
    expect($at()->reorder_point)->toBe(0);    // nothing sells from the warehouse

    $detail = $this->putJson("/api/forecasts/{$mug->id}/location-minimums", ['minimums' => [['location_id' => $second->id, 'min_stock' => 60]]], $this->auth)->assertOk()->json('data');
    expect(collect($detail['locations'])->firstWhere('location_id', $second->id)['min_stock'])->toBe(60)
        ->and($at()->reorder_point)->toBe(60)
        ->and($at()->reorder_date->toDateString())->toBe('2026-09-20');   // 40 is below the minimum

    $this->putJson("/api/forecasts/{$mug->id}/location-minimums", ['minimums' => [['location_id' => $second->id, 'min_stock' => null]]], $this->auth)->assertOk();
    expect($at()->reorder_point)->toBe(0);

    $this->shop->update(['plan' => 'starter']);
    $this->putJson("/api/forecasts/{$mug->id}/location-minimums", ['minimums' => [['location_id' => $second->id, 'min_stock' => 5]]], $this->auth)->assertStatus(402);
});
