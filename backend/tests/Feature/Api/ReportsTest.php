<?php

use App\Models\InventoryLevel;
use App\Models\InventorySnapshot;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('records the inventory value once a day and reports its history', function () {
    $acme = Supplier::factory()->for($this->shop)->create(['lead_time_days' => 10]);
    product($this->shop, $this->location, 'Mug', stock: 100, perDay: 4, attrs: ['unit_cost' => 2, 'supplier_id' => $acme->id]);
    product($this->shop, $this->location, 'Cup', stock: 50, perDay: 4, attrs: ['unit_cost' => null]);
    product($this->shop, $this->location, 'Oversold', stock: -5, perDay: 1, attrs: ['unit_cost' => 9]);
    InventorySnapshot::query()->create(['shop_id' => $this->shop->id, 'date' => '2026-09-10', 'units' => 120, 'value' => 160, 'products_in_stock' => 2, 'products_missing_cost' => 1]);
    InventorySnapshot::query()->create(['shop_id' => $this->shop->id, 'date' => '2026-05-01', 'units' => 1, 'value' => 1, 'products_in_stock' => 1, 'products_missing_cost' => 0]);

    app(ForecastService::class)->runForShop($this->shop);
    app(ForecastService::class)->runForShop($this->shop->fresh()); // a second run the same day replaces the row

    $data = $this->getJson('/api/stock-history?days=90', $this->auth)->assertOk()->json('data');
    expect($data['points'])->toBe([
        ['date' => '2026-09-10', 'units' => 120, 'value' => 160],
        ['date' => '2026-09-20', 'units' => 150, 'value' => 200],   // oversold stock is not inventory; Cup has no cost
    ])->and($data['latest'])->toMatchArray(['products_in_stock' => 2, 'products_missing_cost' => 1])
        ->and($data['change'])->toBe(['from' => '2026-09-10', 'units' => 30, 'value' => 40, 'percent' => 25])
        ->and($data['started_on'])->toBe('2026-09-10');

    expect($this->getJson('/api/stock-history?days=365', $this->auth)->json('data.started_on'))->toBe('2026-05-01');
    $this->getJson('/api/stock-history?days=7', $this->auth)->assertStatus(422);
});

it('lists product data problems with counts and examples', function () {
    $acme = Supplier::factory()->for($this->shop)->create(['lead_time_days' => 10]);
    $noLead = Supplier::factory()->for($this->shop)->create(['lead_time_days' => null]);
    $ok = ['unit_cost' => 2, 'price' => 5, 'supplier_id' => $acme->id];
    product($this->shop, $this->location, 'Fine', stock: 10, perDay: 1, attrs: $ok + ['sku' => 'FINE']);
    product($this->shop, $this->location, 'Twin A', stock: 10, perDay: 1, attrs: $ok + ['sku' => 'TWIN']);
    product($this->shop, $this->location, 'Twin B', stock: 10, perDay: 1, attrs: $ok + ['sku' => 'TWIN']);
    product($this->shop, $this->location, 'Bare', stock: -3, perDay: 1, attrs: ['sku' => null, 'unit_cost' => null, 'price' => null, 'supplier_id' => null]);
    product($this->shop, $this->location, 'Slow supplier', stock: 10, perDay: 1, attrs: ['sku' => 'SLOW', 'unit_cost' => 1, 'price' => 2, 'supplier_id' => $noLead->id]);
    product($this->shop, $this->location, 'Own lead', stock: 10, perDay: 1, attrs: ['sku' => 'OWN', 'unit_cost' => 1, 'price' => 2, 'supplier_id' => null, 'lead_time_override' => 5]);
    Variant::factory()->for($this->shop)->create(['product_title' => 'Untracked', 'tracked' => false, 'sku' => 'UNT']);
    Variant::factory()->for($this->shop)->create(['product_title' => 'Archived', 'is_active' => false, 'sku' => null, 'unit_cost' => null]);

    $data = $this->getJson('/api/data-health', $this->auth)->assertOk()->json('data');
    $by = collect($data['findings'])->keyBy('code');

    expect($data['checked'])->toBe(6)
        ->and($by->map->count->all())->toBe([
            'negative_stock' => 1, 'not_tracked' => 1, 'missing_cost' => 1, 'duplicate_sku' => 2,
            'missing_sku' => 1, 'missing_price' => 1, 'no_supplier' => 2, 'default_lead_time' => 2,
        ])
        ->and($by['duplicate_sku']['severity'])->toBe('warning')
        ->and(array_column($by['duplicate_sku']['sample'], 'name'))->toBe(['Twin A', 'Twin B'])
        ->and(array_column($by['default_lead_time']['sample'], 'name'))->toBe(['Bare', 'Slow supplier'])
        ->and($by['not_tracked']['sample'][0]['name'])->toStartWith('Untracked');
});

it('reports nothing for a clean shop, and only its own products', function () {
    $other = Shop::factory()->create();
    Variant::factory()->for($other)->create(['unit_cost' => null, 'sku' => null]);
    $acme = Supplier::factory()->for($this->shop)->create(['lead_time_days' => 10]);
    product($this->shop, $this->location, 'Fine', stock: 10, perDay: 1, attrs: ['unit_cost' => 2, 'price' => 5, 'supplier_id' => $acme->id, 'sku' => 'FINE']);

    expect($this->getJson('/api/data-health', $this->auth)->assertOk()->json('data'))->toMatchArray(['checked' => 1, 'findings' => []]);
});

it('shows how the forecast moved since an earlier week', function () {
    $mug = product($this->shop, $this->location, 'Mug', stock: 100, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
    expect($this->getJson("/api/forecasts/{$mug->id}", $this->auth)->json('data.previous'))->toBeNull(); // first forecast this week

    DB::table('forecast_snapshots')->insert(['shop_id' => $this->shop->id, 'variant_id' => $mug->id, 'week_start' => '2026-09-07', 'avg_daily_sales' => 3.2, 'avg_source' => 'computed', 'created_at' => now()]);
    expect($this->getJson("/api/forecasts/{$mug->id}", $this->auth)->json('data.previous'))->toBe(['week_start' => '2026-09-07', 'avg' => 3.2]);
});
