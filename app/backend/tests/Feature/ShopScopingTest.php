<?php

use App\Models\DailySale;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Support\ShopContext;
use Illuminate\Database\QueryException;

it('scopes shop-owned models to the authenticated shop and fills shop_id', function () {
    $mine = Shop::factory()->create();
    $theirs = Shop::factory()->create();
    Variant::factory()->for($mine)->count(2)->create();
    Variant::factory()->for($theirs)->count(3)->create();

    app(ShopContext::class)->set($mine);

    expect(Variant::count())->toBe(2)
        ->and(Variant::whereIn('shop_id', [$theirs->id])->count())->toBe(0);

    $supplier = Supplier::create(['name' => 'Acme']);
    expect($supplier->shop_id)->toBe($mine->id);
});

it('does not scope outside a request (jobs scope explicitly)', function () {
    $a = Shop::factory()->create();
    $b = Shop::factory()->create();
    Variant::factory()->for($a)->create();
    Variant::factory()->for($b)->create();

    expect(Variant::count())->toBe(2)
        ->and(Variant::forShop($a)->count())->toBe(1);
});

it('keeps one daily_sales row per variant and date', function () {
    $variant = Variant::factory()->create();
    DailySale::factory()->create(['variant_id' => $variant->id, 'date' => '2026-09-01']);

    DailySale::factory()->create(['variant_id' => $variant->id, 'date' => '2026-09-01']);
})->throws(QueryException::class);

it('uses the shop defaults for lead time and safety days', function () {
    $shop = Shop::factory()->create()->fresh();

    expect($shop->default_lead_time_days)->toBe(14)
        ->and($shop->default_safety_days)->toBe(7);
});
