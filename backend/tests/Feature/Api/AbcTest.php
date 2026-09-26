<?php

use App\Models\DailySale;
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
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

/** 90-day revenue = perDay x 90 x price (the product helper sells every day for 120 days). Total 9990. */
function abcProducts(Shop $shop, Location $loc): array
{
    return [
        'star' => product($shop, $loc, 'Star', stock: 100, perDay: 9, attrs: ['price' => 10, 'unit_cost' => 4]),  // 8100 (81.1%)
        'good' => product($shop, $loc, 'Good', stock: 100, perDay: 3, attrs: ['price' => 5, 'unit_cost' => 4]),   // 1350 (13.5%)
        'tail' => product($shop, $loc, 'Tail', stock: 50, perDay: 1, attrs: ['price' => 5, 'unit_cost' => 2]),    // 450 (4.5%)
        'rare' => product($shop, $loc, 'Rare', stock: 500, perDay: 1, attrs: ['price' => 1, 'unit_cost' => 1]),   // 90 (0.9%)
        'dead' => product($shop, $loc, 'Dead', stock: 20, perDay: 0, attrs: ['price' => 50, 'unit_cost' => 10]),  // 0
    ];
}

it('classifies every tracked product by its share of the last 90 days of revenue', function () {
    $p = abcProducts($this->shop, $this->location);
    $noPrice = product($this->shop, $this->location, 'No price', stock: 10, perDay: 3, attrs: ['price' => null]);

    app(ForecastService::class)->runForShop($this->shop);

    $class = fn (Variant $v) => $v->fresh()->abc_class;
    // Cumulative share before each: 0 -> A, 81% -> B, 94.6% -> B, 99.1% -> C, no revenue -> C.
    expect($class($p['star']))->toBe('A')
        ->and($class($p['good']))->toBe('B')
        ->and($class($p['tail']))->toBe('B')
        ->and($class($p['rare']))->toBe('C')
        ->and($class($p['dead']))->toBe('C')
        ->and($class($noPrice))->toBeNull()
        ->and($p['star']->fresh()->revenue_90d)->toBe('8100.00')
        ->and($p['star']->fresh()->revenue_share)->toBe('0.810811');
});

it('uses net units (returns subtracted) and resets products that are no longer tracked', function () {
    $p = abcProducts($this->shop, $this->location);
    DailySale::query()->where('variant_id', $p['star']->id)->update(['units_returned' => 1]); // 8 net per day
    app(ForecastService::class)->runForShop($this->shop);
    expect($p['star']->fresh()->revenue_90d)->toBe('7200.00');

    $p['star']->update(['tracked' => false]);
    app(ForecastService::class)->runForShop($this->shop);

    expect($p['star']->fresh()->abc_class)->toBeNull()
        ->and($p['star']->fresh()->revenue_90d)->toBe('0.00')
        ->and($p['good']->fresh()->abc_class)->toBe('A');
});

it('keeps the classes when only some products are recomputed', function () {
    $p = abcProducts($this->shop, $this->location);
    app(ForecastService::class)->runForShop($this->shop);

    app(ForecastService::class)->runForShop($this->shop, [$p['star']->id]);

    expect($p['star']->fresh()->abc_class)->toBe('A')->and($p['rare']->fresh()->abc_class)->toBe('C');
});

it('filters and sorts the product list by class and revenue', function () {
    abcProducts($this->shop, $this->location);
    app(ForecastService::class)->runForShop($this->shop);

    $this->getJson('/api/forecasts?abc=A', $this->auth)->assertOk()
        ->assertJsonPath('data.*.name', ['Star'])
        ->assertJsonPath('data.0.abc_class', 'A');
    $this->getJson('/api/forecasts?abc=C&sort=name', $this->auth)->assertJsonPath('data.*.name', ['Dead', 'Rare']);
    $this->getJson('/api/forecasts?sort=revenue', $this->auth)->assertJsonPath('data.0.name', 'Star')->assertJsonPath('data.1.name', 'Good');
    $this->getJson('/api/forecasts?abc=D', $this->auth)->assertUnprocessable();
});

it('explains the class on the product page', function () {
    $p = abcProducts($this->shop, $this->location);
    app(ForecastService::class)->runForShop($this->shop);

    $this->getJson("/api/forecasts/{$p['good']->id}", $this->auth)->assertOk()
        ->assertJsonPath('data.price', 5)
        ->assertJsonPath('data.abc', ['class' => 'B', 'revenue' => 1350, 'share' => 0.135135, 'days' => 90]);
});

it('summarises products, revenue and stock value per class on the dashboard', function () {
    abcProducts($this->shop, $this->location);
    app(ForecastService::class)->runForShop($this->shop);

    $this->getJson('/api/dashboard', $this->auth)->assertOk()
        ->assertJsonPath('data.abc.classes.A', ['count' => 1, 'revenue' => 8100, 'revenue_share' => 0.8108, 'stock_value' => 400])
        ->assertJsonPath('data.abc.classes.B', ['count' => 2, 'revenue' => 1800, 'revenue_share' => 0.1802, 'stock_value' => 500])
        ->assertJsonPath('data.abc.classes.C', ['count' => 2, 'revenue' => 90, 'revenue_share' => 0.009, 'stock_value' => 700])
        ->assertJsonPath('data.abc.unclassified', 0)
        ->assertJsonPath('data.abc.days', 90);
});
