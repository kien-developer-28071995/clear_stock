<?php

use App\Models\DailySale;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Services\Sync\StockHistoryBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->shop = Shop::factory()->create(['timezone' => 'UTC']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->now = CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC');
});

function stockedVariant(Shop $shop, Location $location, int $stock, array $attrs = []): Variant
{
    $variant = Variant::factory()->for($shop)->create($attrs + ['shopify_created_at' => '2025-01-01 00:00:00']);
    InventoryLevel::factory()->create(['shop_id' => $shop->id, 'variant_id' => $variant->id, 'location_id' => $location->id, 'available' => $stock]);

    return $variant;
}

function sold(Variant $v, string $date, int $units, int $returned = 0): void
{
    DailySale::factory()->create(['variant_id' => $v->id, 'date' => $date, 'units_sold' => $units, 'units_returned' => $returned, 'end_of_day_stock' => null]);
}

function flags(Variant $v): array
{
    return DailySale::where('variant_id', $v->id)->orderBy('date')->get()
        ->mapWithKeys(fn ($r) => [$r->date->toDateString() => $r->was_in_stock])->all();
}

it('marks days after the last sale as out of stock when stock is now zero', function () {
    $v = stockedVariant($this->shop, $this->location, 0);
    sold($v, '2026-09-15', 3); // sold the last 3 units on the 15th

    $stats = app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-09-13', $this->now);

    expect(flags($v))->toBe([
        '2026-09-15' => true,   // had sales
        '2026-09-16' => false,
        '2026-09-17' => false,
        '2026-09-18' => false,
        '2026-09-19' => false,
        '2026-09-20' => false,
    ])->and($stats['out_of_stock_days'])->toBe(5);
});

it('keeps every day in stock when stock never ran out', function () {
    $v = stockedVariant($this->shop, $this->location, 20);
    sold($v, '2026-09-15', 3);

    app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-09-13', $this->now);

    expect(collect(flags($v))->every(fn ($inStock) => $inStock))->toBeTrue();
});

it('records yesterday end-of-day stock as a real snapshot', function () {
    $v = stockedVariant($this->shop, $this->location, 10);
    sold($v, '2026-09-20', 2); // today, before the sync ran

    app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-09-13', $this->now);

    expect(DailySale::where('variant_id', $v->id)->where('date', '2026-09-19')->value('end_of_day_stock'))->toBe(12);
});

it('prefers stored snapshots over the backwards estimate', function () {
    $v = stockedVariant($this->shop, $this->location, 5);
    // A restock on the 17th: the snapshot says the 16th ended at 0.
    DailySale::factory()->create(['variant_id' => $v->id, 'date' => '2026-09-16', 'units_sold' => 0, 'end_of_day_stock' => 0]);
    sold($v, '2026-09-18', 1);

    app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-09-13', $this->now);

    // The estimate alone would say the 16th started with 6 units; the snapshot proves it ended at 0,
    // and with no sales that day it started at 0 too.
    expect(flags($v)['2026-09-16'])->toBeFalse()
        ->and(flags($v)['2026-09-18'])->toBeTrue();
});

it('does not go back before the variant existed', function () {
    $v = stockedVariant($this->shop, $this->location, 0, ['shopify_created_at' => '2026-09-18 08:00:00']);
    sold($v, '2026-09-18', 1);

    app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-09-01', $this->now);

    expect(array_key_first(flags($v)))->toBe('2026-09-18');
});

it('skips untracked variants and variants without sales', function () {
    $untracked = stockedVariant($this->shop, $this->location, 0, ['tracked' => false]);
    sold($untracked, '2026-09-15', 3);
    $neverSold = stockedVariant($this->shop, $this->location, 0);

    $stats = app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-09-13', $this->now);

    expect($stats['variants'])->toBe(0)
        ->and(DailySale::where('variant_id', $neverSold->id)->count())->toBe(0)
        ->and(collect(flags($untracked))->every(fn ($inStock) => $inStock))->toBeTrue();
});

it('sums stock over active locations only', function () {
    $v = stockedVariant($this->shop, $this->location, 0);
    $closed = Location::factory()->for($this->shop)->create(['is_active' => false]);
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $v->id, 'location_id' => $closed->id, 'available' => 50]);
    sold($v, '2026-09-19', 1);

    app(StockHistoryBuilder::class)->rebuild($this->shop, '2026-09-13', $this->now);

    expect(flags($v)['2026-09-20'])->toBeFalse();
});
