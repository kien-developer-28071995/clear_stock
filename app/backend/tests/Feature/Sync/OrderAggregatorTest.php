<?php

use App\Models\DailySale;
use App\Models\Shop;
use App\Models\Variant;
use App\Services\Sync\OrderAggregator;

beforeEach(function () {
    $this->shop = Shop::factory()->create(['timezone' => 'America/New_York']);
    $this->a = Variant::factory()->for($this->shop)->create(['shopify_variant_id' => 101]);
    $this->b = Variant::factory()->for($this->shop)->create(['shopify_variant_id' => 102]);
    $this->bundle = Variant::factory()->for($this->shop)->bundle()->create(['shopify_variant_id' => 900]);
});

function sales(Variant $v): array
{
    return DailySale::forShop($v->shop_id)->where('variant_id', $v->id)->orderBy('date')->get()
        ->mapWithKeys(fn ($r) => [$r->date->toDateString() => [$r->units_sold, $r->units_returned]])->all();
}

function lineItem(int $order, int $variant, int $qty, ?int $current = null, ?array $group = null): array
{
    return [
        'quantity' => $qty,
        'currentQuantity' => $current ?? $qty,
        'variant' => ['id' => gid('ProductVariant', $variant)],
        'lineItemGroup' => $group,
        '__parentId' => gid('Order', $order),
    ];
}

function order(int $id, string $processedAt, ?string $cancelledAt = null): array
{
    return ['id' => gid('Order', $id), 'processedAt' => $processedAt, 'cancelledAt' => $cancelledAt];
}

it('aggregates line items per variant and local day', function () {
    $file = jsonlFile([
        order(1, '2026-09-10T15:00:00Z'),
        lineItem(1, 101, 2),
        lineItem(1, 102, 1),
        order(2, '2026-09-10T20:00:00Z'),
        lineItem(2, 101, 3),
    ]);

    $stats = app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-09-10' => [5, 0]])
        ->and(sales($this->b))->toBe(['2026-09-10' => [1, 0]])
        ->and($stats)->toMatchArray(['orders' => 2, 'line_items' => 3]);
});

it('uses the shop timezone for the day boundary', function () {
    // 02:30 UTC on the 11th is still the 10th in New York.
    $file = jsonlFile([order(1, '2026-09-11T02:30:00Z'), lineItem(1, 101, 1)]);

    app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-09-10' => [1, 0]]);
});

it('ignores cancelled orders and counts refunded units as returns', function () {
    $file = jsonlFile([
        order(1, '2026-09-10T15:00:00Z', cancelledAt: '2026-09-10T16:00:00Z'),
        lineItem(1, 101, 9, current: 0),
        order(2, '2026-09-10T15:00:00Z'),
        lineItem(2, 101, 4, current: 1),
    ]);

    app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-09-10' => [4, 3]]);
});

it('handles child lines that appear before their parent order', function () {
    $file = jsonlFile([lineItem(7, 101, 2), order(7, '2026-09-12T15:00:00Z')]);

    app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-09-12' => [2, 0]]);
});

it('counts native bundle components as component sales and the bundle once per order', function () {
    $group = ['id' => gid('LineItemGroup', 55), 'quantity' => 2, 'variantId' => gid('ProductVariant', 900)];
    $file = jsonlFile([
        order(1, '2026-09-10T15:00:00Z'),
        lineItem(1, 101, 2, group: $group),
        lineItem(1, 102, 4, group: $group),
    ]);

    app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-09-10' => [2, 0]])
        ->and(sales($this->b))->toBe(['2026-09-10' => [4, 0]])
        ->and(sales($this->bundle))->toBe(['2026-09-10' => [2, 0]]);
});

it('skips line items of deleted or unknown variants', function () {
    $file = jsonlFile([
        order(1, '2026-09-10T15:00:00Z'),
        ['quantity' => 1, 'currentQuantity' => 1, 'variant' => null, 'lineItemGroup' => null, '__parentId' => gid('Order', 1)],
        lineItem(1, 999999, 1),
    ]);

    app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01');

    expect(DailySale::count())->toBe(0);
});

it('replaces sales inside the window, keeps older days and stock snapshots', function () {
    DailySale::factory()->create(['variant_id' => $this->a->id, 'date' => '2026-08-01', 'units_sold' => 7]);
    DailySale::factory()->create(['variant_id' => $this->a->id, 'date' => '2026-09-05', 'units_sold' => 7, 'end_of_day_stock' => 12]);
    DailySale::factory()->create(['variant_id' => $this->b->id, 'date' => '2026-09-06', 'units_sold' => 3]); // order cancelled since

    $file = jsonlFile([order(1, '2026-09-05T15:00:00Z'), lineItem(1, 101, 2)]);

    app(OrderAggregator::class)->import($this->shop, $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-08-01' => [7, 0], '2026-09-05' => [2, 0]])
        ->and(sales($this->b))->toBe(['2026-09-06' => [0, 0]])
        ->and(DailySale::where('variant_id', $this->a->id)->where('date', '2026-09-05')->value('end_of_day_stock'))->toBe(12);
});

it('never touches other shops', function () {
    $other = Variant::factory()->create(['shopify_variant_id' => 101]); // same Shopify id, other shop
    DailySale::factory()->create(['variant_id' => $other->id, 'date' => '2026-09-05', 'units_sold' => 5]);

    app(OrderAggregator::class)->import($this->shop, null, '2026-09-01');

    expect(DailySale::where('variant_id', $other->id)->value('units_sold'))->toBe(5);
});

it('leaves out orders the merchant excluded by tag or source', function () {
    $this->shop->update(['excluded_order_tags' => ['Wholesale'], 'excluded_order_sources' => ['pos']]);
    $file = jsonlFile([
        order(1, '2026-09-10T15:00:00Z') + ['tags' => ['vip', 'wholesale']],          // tag, any letter case
        lineItem(1, 101, 50),
        order(2, '2026-09-10T15:00:00Z') + ['tags' => [], 'sourceName' => 'pos'],
        lineItem(2, 101, 7),
        order(3, '2026-09-10T15:00:00Z') + ['tags' => ['vip'], 'sourceName' => 'web'],
        lineItem(3, 101, 2),
        order(4, '2026-09-10T15:00:00Z') + ['sourceName' => 'shopify_draft_order'],  // drafts are not excluded here
        lineItem(4, 101, 1),
    ]);

    $stats = app(OrderAggregator::class)->import($this->shop->fresh(), $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-09-10' => [3, 0]])
        ->and($stats)->toMatchArray(['orders' => 4, 'excluded_orders' => 2]);
});

it('counts every order when order exclusions are switched off app-wide', function () {
    $this->shop->update(['excluded_order_tags' => ['Wholesale'], 'excluded_order_sources' => ['pos']]);
    config(['features.order_exclusions' => false]);
    $file = jsonlFile([
        order(1, '2026-09-10T15:00:00Z') + ['tags' => ['wholesale']],
        lineItem(1, 101, 50),
        order(2, '2026-09-10T15:00:00Z') + ['tags' => [], 'sourceName' => 'pos'],
        lineItem(2, 101, 7),
    ]);

    $stats = app(OrderAggregator::class)->import($this->shop->fresh(), $file, '2026-09-01');

    expect(sales($this->a))->toBe(['2026-09-10' => [57, 0]])
        ->and($stats)->toMatchArray(['orders' => 2, 'excluded_orders' => 0]);
});

// (A line that is not JSON at all still fails the sync: that is a cut-off download, to be fetched again, not skipped.)
it('imports what it can from an export full of nonsense, without failing and without counting the nonsense', function () {
    $path = tempnam(sys_get_temp_dir(), 'jsonl');
    file_put_contents($path, implode("\n", [
        json_encode(order(1, '2026-09-10T15:00:00Z')),
        json_encode(lineItem(1, 101, 2)),                                              // the one good line
        '',
        '[]',
        '"a string"',
        '12',
        'null',
        json_encode(['id' => gid('Order', 2)]),                                         // order without dates
        json_encode(['id' => gid('Order', 3), 'processedAt' => 'not a date', 'cancelledAt' => 'nope']),
        json_encode(['id' => 12345, 'processedAt' => ['x'], 'tags' => 'vip']),          // wrong types everywhere
        json_encode(lineItem(2, 101, 5)),                                               // its order has no date
        json_encode(lineItem(999, 101, 5)),                                             // its order never came
        json_encode(['quantity' => 'many', 'currentQuantity' => null, 'variant' => 'x', '__parentId' => gid('Order', 1)]),
        json_encode(['quantity' => -4, 'currentQuantity' => -9, 'variant' => ['id' => gid('ProductVariant', 101)], '__parentId' => gid('Order', 1)]),
        json_encode(['quantity' => PHP_INT_MAX, 'currentQuantity' => PHP_INT_MAX, 'variant' => ['id' => gid('ProductVariant', 102)], '__parentId' => gid('Order', 1)]),
        json_encode(['quantity' => 1, 'variant' => ['id' => 'gid://shopify/ProductVariant/abc'], '__parentId' => gid('Order', 1)]),
        json_encode(['quantity' => 1, 'variant' => null, '__parentId' => ['x']]),
        json_encode(['__parentId' => gid('Order', 1), 'lineItemGroup' => 'x', 'quantity' => 1, 'variant' => ['id' => gid('ProductVariant', 101)]]),
    ]));

    $stats = app(OrderAggregator::class)->import($this->shop, $path, '2026-09-01');

    $a = sales($this->a);
    expect($a)->toHaveKey('2026-09-10')
        ->and($a['2026-09-10'][0])->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3)   // the good line (and at most the grouped one)
        ->and($a['2026-09-10'][1])->toBe(0)
        ->and(array_keys($a))->toBe(['2026-09-10'])
        ->and(DailySale::forShop($this->shop->id)->where('units_sold', '<', 0)->count())->toBe(0)
        ->and(DailySale::forShop($this->shop->id)->where('units_sold', '>', 1_000_000_000)->count())->toBe(0)
        ->and($stats['orders'])->toBeGreaterThanOrEqual(1);
});
