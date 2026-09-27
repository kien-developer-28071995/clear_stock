<?php

use App\Enums\Confidence;
use App\Services\Forecast\ExplanationFormatter;
use App\Services\Forecast\ForecastCalculator;
use App\Services\Forecast\ForecastInput;
use Carbon\CarbonImmutable;

const AS_OF = '2026-09-20';

/**
 * Build daily history ending yesterday. $perDay: int|callable(int $daysAgo, string $date): int|array{sold?: int, returned?: int, in_stock?: bool}
 */
function history(int $days, int|array|callable $perDay): array
{
    $out = [];
    $asOf = CarbonImmutable::parse(AS_OF);
    for ($ago = 1; $ago <= $days; $ago++) {
        $date = $asOf->subDays($ago)->toDateString();
        $v = is_callable($perDay) ? $perDay($ago, $date) : $perDay;
        $row = is_array($v) ? $v : ['sold' => $v];
        $out[$date] = ['sold' => $row['sold'] ?? 0, 'returned' => $row['returned'] ?? 0, 'in_stock' => $row['in_stock'] ?? true];
    }

    return $out;
}

function input(array $days, int $stock = 100, array $overrides = [], array $extra = []): ForecastInput
{
    $asOf = CarbonImmutable::parse(AS_OF);

    return new ForecastInput(...array_merge([
        'variantId' => 1,
        'asOf' => $asOf,
        'coverageStart' => $days === [] ? $asOf->toDateString() : min(array_keys($days)),
        'currentStock' => $stock,
        'days' => $days,
        'shopLeadTimeDays' => 14,
        'shopSafetyDays' => 7,
        'overrides' => $overrides,
    ], $extra));
}

function calc(ForecastInput $in)
{
    return (new ForecastCalculator(require __DIR__.'/../../config/forecast.php'))->calculate($in);
}

it('forecasts a steady seller', function () {
    $r = calc(input(history(120, 4), stock: 100));

    expect($r->avgDailySales)->toBe(4.0)
        ->and($r->daysOfCover)->toBe(25.0)
        ->and($r->stockoutDate)->toBe('2026-10-15')
        ->and($r->reorderPoint)->toBe(84)             // 4 x (14 lead + 7 safety)
        ->and($r->reorderDate)->toBe('2026-09-24')     // (100 - 84) / 4 = 4 days
        ->and($r->suggestedQty)->toBe(104)            // 4 x (14 + 7 + 30) - 100
        ->and($r->confidence)->toBe(Confidence::High);

    $e = $r->explanation;
    expect(array_column($e['windows'], 'avg'))->toBe([4.0, 4.0, 4.0])
        ->and(array_column($e['windows'], 'weight'))->toBe([0.2, 0.5, 0.3])
        ->and($e['lead_time'])->toBe(['days' => 14, 'source' => 'shop_default'])
        ->and($e['safety'])->toBe(['days' => 7, 'source' => 'shop_default', 'units' => 28.0])
        ->and($e['reorder']['lead_time_demand'])->toBe(56.0)
        ->and($e['avg_source'])->toBe('computed');
});

it('excludes out-of-stock days from the average and reports them', function () {
    // Sells 6/day when in stock; out of stock (0 sales) for 10 of the last 30 days.
    $days = history(120, fn ($ago) => $ago >= 5 && $ago < 15 ? ['sold' => 0, 'in_stock' => false] : 6);

    $r = calc(input($days, stock: 0));

    $w30 = collect($r->explanation['windows'])->firstWhere('days', 30);
    expect($r->avgDailySales)->toBe(6.0)          // not 4.0 (which counting stock-out days would give)
        ->and($w30['excluded_out_of_stock_days'])->toBe(10)
        ->and($w30['in_stock_days'])->toBe(20)
        ->and($w30['units'])->toBe(120.0);
});

it('flags a variant that is out of stock right now', function () {
    $r = calc(input(history(60, 3), stock: 0));

    expect($r->daysOfCover)->toBe(0.0)
        ->and($r->stockoutDate)->toBe(AS_OF)
        ->and($r->reorderDate)->toBe(AS_OF)
        ->and($r->suggestedQty)->toBe(153); // 3 x 51
});

it('subtracts returned units from demand', function () {
    $r = calc(input(history(120, ['sold' => 5, 'returned' => 1])));

    expect($r->avgDailySales)->toBe(4.0);
});

it('has low confidence and skips windows with too little history', function () {
    $r = calc(input(history(8, 2), stock: 30));

    $windows = collect($r->explanation['windows'])->keyBy('days');
    expect($r->avgDailySales)->toBe(2.0)
        ->and($windows[7]['weight'])->toBe(1.0)        // only the 7-day window is usable
        ->and($windows[30]['avg'])->toBeNull()
        ->and($windows[90]['weight'])->toBe(0.0)
        ->and($r->confidence)->toBe(Confidence::Low)
        ->and(array_column($r->explanation['confidence']['reasons'], 'code'))->toContain('little_history');
});

it('has low confidence when weekly sales swing a lot', function () {
    // One week of 20/day, then three weeks with no sales, repeating (e.g. sold only at monthly markets).
    $days = history(120, fn ($ago) => intdiv($ago - 1, 7) % 4 === 0 ? 20 : 0);

    $r = calc(input($days));

    expect($r->confidence)->toBe(Confidence::Low)
        ->and(array_column($r->explanation['confidence']['reasons'], 'code'))->toContain('volatile');
});

it('gives medium confidence to a slow but steady seller', function () {
    // One unit every 4 days: daily data looks spiky, weekly rates are stable; ~22 units/90 days.
    $r = calc(input(history(120, fn ($ago) => $ago % 4 === 0 ? 1 : 0)));

    expect($r->confidence)->toBe(Confidence::Medium)
        ->and($r->explanation['confidence']['weekly_cv'])->toBeLessThan(0.5)
        ->and($r->explanation['confidence']['units_90'])->toBeLessThan(30.0);
});

it('forecasts nothing for a variant with no sales', function () {
    $r = calc(input(history(120, 0), stock: 40));

    expect($r->avgDailySales)->toBe(0.0)
        ->and($r->daysOfCover)->toBeNull()
        ->and($r->stockoutDate)->toBeNull()
        ->and($r->reorderDate)->toBeNull()
        ->and($r->reorderPoint)->toBe(0)
        ->and($r->suggestedQty)->toBe(0)
        ->and($r->confidence)->toBe(Confidence::Low);
});

it('weights the recent week when sales change', function () {
    // 2/day for months, 10/day in the last 7 days.
    $r = calc(input(history(120, fn ($ago) => $ago <= 7 ? 10 : 2)));

    // 7d: 10, 30d: (70 + 46) / 30 = 3.87, 90d: (70 + 166) / 90 = 2.62 -> 0.2*10 + 0.5*3.87 + 0.3*2.62
    expect($r->avgDailySales)->toBe(4.72);
});

describe('seasonality', function () {
    it('applies last year\'s next-30-days vs previous-30-days ratio', function () {
        // Last year: 2/day before this date, 4/day after it (x2). This year steady 3/day.
        $days = history(400, function ($ago, $date) {
            $ly = CarbonImmutable::parse(AS_OF)->subYear()->toDateString();
            if ($ago > 300) {
                return $date >= $ly ? 4 : 2;
            }

            return 3;
        });

        $r = calc(input($days));

        $s = $r->explanation['seasonality'];
        expect($s['applied'])->toBeTrue()
            ->and($s['factor'])->toBe(2.0)
            ->and($s['last_year']['previous_period']['avg'])->toBe(2.0)
            ->and($s['last_year']['next_period']['avg'])->toBe(4.0)
            ->and($r->explanation['base_avg'])->toBe(3.0)
            ->and($r->avgDailySales)->toBe(6.0);
    });

    it('ignores small changes as noise', function () {
        $days = history(400, function ($ago, $date) {
            $ly = CarbonImmutable::parse(AS_OF)->subYear()->toDateString();

            return $ago > 300 ? ($date >= $ly ? 21 : 20) : 3; // +5% last year
        });

        $s = calc(input($days))->explanation['seasonality'];
        expect($s)->toMatchArray(['applied' => false, 'factor' => 1.0, 'reason' => 'no_significant_change', 'raw_factor' => 1.05]);
    });

    it('is not fooled by weekly order patterns', function () {
        // Wholesale-style: 14 units every Monday, nothing else, all year.
        $days = history(400, fn ($ago, $date) => CarbonImmutable::parse($date)->isMonday() ? 14 : 0);

        expect(calc(input($days))->explanation['seasonality']['applied'])->toBeFalse();
    });

    it('is skipped without a year of history', function () {
        $r = calc(input(history(200, 3)));

        expect($r->explanation['seasonality'])->toMatchArray(['applied' => false, 'factor' => 1.0, 'reason' => 'not_enough_history'])
            ->and($r->avgDailySales)->toBe(3.0);
    });

    it('is skipped when the item was out of stock last year', function () {
        $days = history(400, fn ($ago) => $ago > 330 ? ['sold' => 0, 'in_stock' => false] : 3);

        expect(calc(input($days))->explanation['seasonality']['reason'])->toBe('out_of_stock_last_year');
    });

    it('is skipped when last year had too few sales', function () {
        $days = history(400, fn ($ago) => $ago > 330 ? ($ago % 10 === 0 ? 1 : 0) : 3);

        expect(calc(input($days))->explanation['seasonality']['reason'])->toBe('too_few_sales_last_year');
    });

    it('is clamped to protect against one-off spikes', function () {
        $days = history(400, function ($ago, $date) {
            $ly = CarbonImmutable::parse(AS_OF)->subYear()->toDateString();

            return $ago > 300 ? ($date >= $ly ? 50 : 1) : 3;
        });

        $s = calc(input($days))->explanation['seasonality'];
        expect($s['factor'])->toBe(2.5)
            ->and($s['raw_factor'])->toBe(50.0)
            ->and($s['clamped'])->toBeTrue();
    });
});

describe('bundles', function () {
    it('adds component demand from manual bundle sales', function () {
        // Own sales 2/day; a gift box containing 3 of this item sells 1/day.
        $bundleDays = array_map(fn () => 1, history(120, 0));
        $in = input(history(120, 2), extra: ['bundles' => [
            77 => ['name' => 'Gift box', 'quantity' => 3, 'days' => $bundleDays],
        ]]);

        $r = calc($in);

        expect($r->avgDailySales)->toBe(5.0)
            ->and($r->explanation['bundles'])->toBe([[
                'bundle_variant_id' => 77, 'name' => 'Gift box', 'quantity_per_bundle' => 3,
                'bundles_sold_30d' => 30, 'units_per_day' => 3.0,
            ]]);
    });

    it('does not count bundle sales on days the component was out of stock', function () {
        $days = history(120, fn ($ago) => $ago <= 10 ? ['sold' => 0, 'in_stock' => false] : 2);
        $bundleDays = array_map(fn () => 1, history(120, 0));

        $r = calc(input($days, extra: ['bundles' => [77 => ['name' => 'Box', 'quantity' => 1, 'days' => $bundleDays]]]));

        expect($r->explanation['bundles'][0]['bundles_sold_30d'])->toBe(20)
            ->and($r->avgDailySales)->toBe(3.0);
    });
});

describe('overrides and settings', function () {
    it('uses the merchant\'s average instead of the computed one', function () {
        $r = calc(input(history(120, 4), stock: 100, overrides: [
            'avg_daily_sales' => ['value' => 10, 'note' => 'Promo in October', 'expires_at' => '2026-10-31'],
        ]));

        expect($r->avgDailySales)->toBe(10.0)
            ->and($r->reorderPoint)->toBe(210)
            ->and($r->explanation['avg_source'])->toBe('override')
            ->and($r->explanation['computed_avg'])->toBe(4.0)
            ->and($r->explanation['overrides'][0])->toBe(['field' => 'avg_daily_sales', 'value' => 10.0, 'note' => 'Promo in October', 'expires_at' => '2026-10-31'])
            ->and(array_column($r->explanation['confidence']['reasons'], 'code'))->toContain('average_overridden');
    });

    it('resolves lead time: override > variant > supplier > shop default', function (array $extra, array $overrides, array $expected) {
        $r = calc(input(history(120, 1), overrides: $overrides, extra: $extra));

        expect($r->explanation['lead_time'])->toBe($expected);
    })->with([
        'shop default' => [[], [], ['days' => 14, 'source' => 'shop_default']],
        'supplier' => [['supplierName' => 'Acme', 'supplierLeadTimeDays' => 30], [], ['days' => 30, 'source' => 'supplier', 'supplier' => 'Acme']],
        'variant beats supplier' => [['supplierName' => 'Acme', 'supplierLeadTimeDays' => 30, 'variantLeadTimeDays' => 21], [], ['days' => 21, 'source' => 'variant']],
        'override beats all' => [['variantLeadTimeDays' => 21], ['lead_time_days' => ['value' => 5, 'note' => null, 'expires_at' => null]], ['days' => 5, 'source' => 'override']],
    ]);

    it('resolves safety days: override > variant > shop default', function () {
        expect(calc(input(history(120, 1), extra: ['variantSafetyDays' => 3]))->explanation['safety']['source'])->toBe('variant')
            ->and(calc(input(history(120, 1), overrides: ['safety_days' => ['value' => 0, 'note' => null, 'expires_at' => null]], extra: ['variantSafetyDays' => 3]))->explanation['safety'])
            ->toBe(['days' => 0, 'source' => 'override', 'units' => 0.0]);
    });
});

it('ignores history before the coverage start', function () {
    $days = history(120, fn ($ago) => $ago > 20 ? 100 : 2);

    $r = calc(input($days, extra: ['coverageStart' => CarbonImmutable::parse(AS_OF)->subDays(20)->toDateString()]));

    expect($r->avgDailySales)->toBe(2.0)
        ->and($r->explanation['history']['days_available'])->toBe(20);
});

describe('stock on the way (Shopify incoming)', function () {
    it('orders only what is still missing after incoming stock arrives', function () {
        $days = history(120, 4);

        $without = calc(input($days, stock: 20));
        $with = calc(input($days, stock: 20, extra: ['incomingStock' => 50]));

        expect($with->suggestedQty)->toBe($without->suggestedQty - 50)
            ->and($with->incomingStock)->toBe(50)
            ->and($with->explanation['stock'])->toBe(['current' => 20, 'incoming' => 50, 'position' => 70, 'ordered' => null]);
    });

    it('moves the reorder date by the stock position but keeps the stock-out date on hand only', function () {
        $days = history(120, 4); // reorder point 84 at 14 + 7 days
        $r = calc(input($days, stock: 20, extra: ['incomingStock' => 200]));

        expect($r->stockoutDate)->toBe('2026-09-25')            // 20 on hand / 4 per day
            ->and($r->reorderDate)->toBe('2026-10-24')           // (220 - 84) / 4 = 34 days
            ->and($r->daysOfCover)->toBe(5.0);
    });

    it('needs no order when incoming stock covers the next cycle, and says why', function () {
        $r = calc(input(history(120, 4), stock: 0, extra: ['incomingStock' => 1000]));

        expect($r->suggestedQty)->toBe(0)
            ->and(array_column((new ExplanationFormatter)->lines($r->explanation), 'code'))
            ->toContain('incoming_stock')->toContain('out_of_stock_incoming_covers')->not->toContain('order_today_out_of_stock');
    });
});

describe('order rounding (MOQ and pack size)', function () {
    // A steady 4/day seller with 100 in stock needs 104 (see "forecasts a steady seller").
    it('rounds the suggestion to what the supplier accepts', function (?int $moq, ?int $pack, int $expected) {
        $r = calc(input(history(120, 4), stock: 100, extra: ['minOrderQty' => $moq, 'packSize' => $pack]));

        expect($r->suggestedQty)->toBe($expected)
            ->and($r->explanation['reorder']['rounding']['needed'])->toBe(104)
            ->and($r->reorderPoint)->toBe(84); // rounding never changes when to order
    })->with([
        'no rules' => [null, null, 104],
        'whole packs of 12' => [null, 12, 108],
        'already a whole pack' => [null, 8, 104],
        'minimum 150' => [150, null, 150],
        'minimum below the need' => [50, null, 104],
        'minimum then packs' => [150, 12, 156],
        'pack of 1 is no rule' => [null, 1, 104],
    ]);

    it('never turns "nothing to order" into an order', function () {
        $r = calc(input(history(120, 4), stock: 5000, extra: ['minOrderQty' => 100, 'packSize' => 12]));

        expect($r->suggestedQty)->toBe(0);
    });

    it('explains the rounding', function (?int $moq, ?int $pack, string $code) {
        $r = calc(input(history(120, 4), stock: 100, extra: ['minOrderQty' => $moq, 'packSize' => $pack]));
        $lines = collect((new ExplanationFormatter)->lines($r->explanation));

        expect($lines->firstWhere('code', $code))->not->toBeNull()
            ->and((new ExplanationFormatter)->sentences($r->explanation, 'en'))->toContain(match ($code) {
                'rounded_pack' => 'You need 104; rounded up to whole packs of 12 → 108.',
                'rounded_min' => 'You need 104; the supplier minimum is 150, so order 150.',
                'rounded_min_and_pack' => 'You need 104; the supplier minimum is 150, in packs of 12 → 156.',
            });
    })->with([
        [null, 12, 'rounded_pack'],
        [150, null, 'rounded_min'],
        [150, 12, 'rounded_min_and_pack'],
    ]);
});

describe('manual min / max (Stocky style)', function () {
    // Steady 4/day, lead 14 + safety 7: computed reorder point 84, order-up-to 204.
    it('reorders at the minimum and fills up to the maximum', function () {
        $r = calc(input(history(120, 4), stock: 100, extra: ['minStock' => 120, 'maxStock' => 300]));

        expect($r->reorderPoint)->toBe(120)
            ->and($r->reorderDate)->toBe('2026-09-20')  // 100 <= 120: today
            ->and($r->suggestedQty)->toBe(200)          // 300 - 100
            ->and($r->explanation['reorder'])->toMatchArray(['computed_point' => 84, 'min_stock' => 120, 'max_stock' => 300]);
    });

    it('uses a manual minimum alone, and a maximum alone', function () {
        $minOnly = calc(input(history(120, 4), stock: 100, extra: ['minStock' => 40]));
        expect($minOnly->reorderPoint)->toBe(40)
            ->and($minOnly->reorderDate)->toBe('2026-10-05') // (100 - 40) / 4 = 15 days
            ->and($minOnly->suggestedQty)->toBe(104);        // order-up-to still from the forecast

        $maxOnly = calc(input(history(120, 4), stock: 100, extra: ['maxStock' => 150]));
        expect($maxOnly->reorderPoint)->toBe(84)
            ->and($maxOnly->suggestedQty)->toBe(50);          // 150 - 100
    });

    it('counts stock on the way against the minimum', function () {
        $r = calc(input(history(120, 4), stock: 100, extra: ['minStock' => 120, 'maxStock' => 300, 'incomingStock' => 50]));

        expect($r->reorderDate)->not->toBe('2026-09-20') // 150 > 120
            ->and($r->suggestedQty)->toBe(150);            // 300 - 150
    });

    it('reorders products without sales only through the minimum', function () {
        $none = history(120, 0);
        $below = calc(input($none, stock: 3, extra: ['minStock' => 5, 'maxStock' => 20]));
        $above = calc(input($none, stock: 10, extra: ['minStock' => 5, 'maxStock' => 20]));
        $f = new ExplanationFormatter;

        expect($below->reorderDate)->toBe('2026-09-20')
            ->and($below->suggestedQty)->toBe(17)
            ->and($f->sentences($below->explanation, 'en'))->toContain('Reorder point set by you: 5 units.')
            ->toContain('Orders fill up to your maximum of 20 units.')
            ->toContain('3 in stock (with stock on the way) is at or below your minimum → order 17 units today.')
            ->and($above->reorderDate)->toBeNull()
            ->and($above->suggestedQty)->toBe(10)
            ->and(array_column($f->lines($above->explanation), 'code'))->toContain('above_min')
            ->and(calc(input($none, stock: 3))->reorderDate)->toBeNull(); // no minimum: no reorder
    });

    it('still rounds a min/max order to packs', function () {
        $r = calc(input(history(120, 4), stock: 100, extra: ['minStock' => 120, 'maxStock' => 300, 'packSize' => 24]));

        expect($r->suggestedQty)->toBe(216); // 200 -> 9 packs of 24
    });
});

describe('overstock', function () {
    // Steady 4/day, lead 14 + safety 7 + 30-day cycle: hold 204 units.
    it('measures stock above the level to hold', function () {
        $fine = calc(input(history(120, 4), stock: 250));
        $over = calc(input(history(120, 4), stock: 250, extra: ['incomingStock' => 100]));

        expect([$fine->targetStock, $fine->excessUnits, $fine->explanation['reorder']['overstock']])->toBe([204, 46, false]) // 23%: not overstock
            ->and([$over->targetStock, $over->excessUnits, $over->explanation['reorder']['overstock']])->toBe([204, 146, true]); // 72%, incl. on the way
    });

    it('uses the merchant max as the level to hold', function () {
        $r = calc(input(history(120, 4), stock: 400, extra: ['maxStock' => 200]));

        expect([$r->targetStock, $r->excessUnits, $r->explanation['reorder']['overstock']])->toBe([200, 200, true]);
    });

    it('explains it, and leaves products without sales to "slow"', function () {
        $r = calc(input(history(120, 4), stock: 400));
        expect((new ExplanationFormatter)->sentences($r->explanation, 'en'))
            ->toContain('196 units more than the 204 to hold (lead time, safety and the next order cycle): hold off reordering, or run a promotion.');

        $none = calc(input(history(120, 0), stock: 400));
        expect([$none->excessUnits, $none->explanation['reorder']['overstock']])->toBe([0, false]);
    });
});

describe('reference product (new products)', function () {
    $reference = fn (?float $avg = 10.0, int $percent = 50) => ['reference' => ['variant_id' => 9, 'name' => 'Mug classic', 'avg' => $avg, 'percent' => $percent]];

    it('blends the reference rate with its own in proportion to its in-stock days', function () use ($reference) {
        // 6 days at 2/day: own weight 6/30 = 20%, reference 10/day x 50% = 5 -> 0.2 x 2 + 0.8 x 5 = 4.4.
        $r = calc(input(history(6, 2), stock: 100, extra: $reference()));

        expect($r->avgDailySales)->toBe(4.4)
            ->and($r->explanation['avg_source'])->toBe('reference')
            ->and($r->explanation['own_avg'])->toBe(2.0)
            ->and($r->explanation['reference'])->toMatchArray(['applied' => true, 'reference_avg' => 5.0, 'own_days' => 6, 'own_weight' => 0.2])
            ->and(collect($r->explanation['confidence']['reasons'])->pluck('code'))->toContain('uses_reference');

        $lines = app(ExplanationFormatter::class)->lines($r->explanation);
        expect(collect($lines)->firstWhere('code', 'reference_blend')['params'])
            ->toBe(['name' => 'Mug classic', 'ref_avg' => 5, 'percent' => 50, 'count' => 6, 'ref_share' => 80, 'avg' => 4.4]);
    });

    it('uses only the reference before the first sale', function () use ($reference) {
        expect(calc(input([], stock: 100, extra: $reference(8.0, 100)))->avgDailySales)->toBe(8.0);
    });

    it('stops borrowing once the product has enough history of its own', function () use ($reference) {
        $r = calc(input(history(60, 3), stock: 100, extra: $reference()));

        expect($r->avgDailySales)->toBe(3.0)
            ->and($r->explanation['avg_source'])->toBe('computed')
            ->and($r->explanation['reference'])->toMatchArray(['applied' => false, 'reason' => 'enough_history', 'own_days' => 60])
            ->and(collect(app(ExplanationFormatter::class)->lines($r->explanation))->pluck('code'))->toContain('reference_done');
    });

    it('lets a manual sales rate win, and ignores a reference without a forecast', function () use ($reference) {
        $override = ['avg_daily_sales' => ['value' => 7.0, 'note' => null, 'expires_at' => null]];
        expect(calc(input(history(6, 2), stock: 100, overrides: $override, extra: $reference()))->avgDailySales)->toBe(7.0);

        $r = calc(input(history(6, 2), stock: 100, extra: $reference(null)));
        expect($r->avgDailySales)->toBe(2.0)
            ->and($r->explanation['reference']['reason'])->toBe('no_forecast');
    });
});

it('caps a one-off spike to the usual level when asked', function () {
    // A wholesale order of 80 among days of 2/day.
    $days = history(120, fn ($ago) => $ago === 10 ? 80 : 2);

    $plain = calc(input($days));
    $capped = calc(input($days, extra: ['filterSpikes' => true]));

    expect($plain->avgDailySales)->toBeGreaterThan(2.5)
        ->and($capped->avgDailySales)->toBe(2.0)
        ->and($capped->explanation['spikes']['applied'])->toBeTrue()
        ->and($capped->explanation['spikes']['days'][0])->toMatchArray(['date' => '2026-09-10', 'units' => 80.0])
        ->and($capped->explanation['spikes']['units_removed'])->toBeGreaterThan(77.0)
        ->and($plain->explanation['spikes'])->toBeNull();
});

it('leaves spikes of rarely selling products alone', function () {
    // Sells on 3 days only: not enough to know what is usual.
    $days = history(120, fn ($ago) => match ($ago) {
        5 => 30, 40, 70 => 1, default => 0
    });

    $r = calc(input($days, extra: ['filterSpikes' => true]));

    expect($r->explanation['spikes']['applied'])->toBeFalse();
});

it('keeps big days that come back every week: that is real demand', function () {
    // A wholesale customer buys 48 every Wednesday on top of 2/day.
    $days = history(120, fn ($ago) => $ago % 7 === 3 ? 50 : 2);

    $r = calc(input($days, extra: ['filterSpikes' => true]));

    expect($r->explanation['spikes'])->toMatchArray(['applied' => false, 'reason' => 'recurring'])
        ->and($r->avgDailySales)->toBeGreaterThan(8.0);
});

it('does not treat a steady busy day pattern as a spike', function () {
    // Weekends sell 3x weekdays: below the 5x factor.
    $days = history(120, fn ($ago) => $ago % 7 < 2 ? 12 : 4);

    expect(calc(input($days, extra: ['filterSpikes' => true]))->explanation['spikes']['applied'])->toBeFalse();
});

it('estimates sales lost on out-of-stock days of the last 30 days', function () {
    $days = history(120, fn ($ago) => $ago <= 5 ? ['sold' => 0, 'in_stock' => false] : 4);

    $r = calc(input($days, stock: 0));

    expect($r->lostUnits30d)->toBe(20.0)   // 5 days x 4/day
        ->and($r->explanation['lost_sales'])->toBe(['days' => 30, 'out_of_stock_days' => 5, 'units' => 20.0]);
});

it('uses the supplier order cycle for the suggested order', function () {
    $r = calc(input(history(120, 4), stock: 100, extra: ['orderCycleDays' => 7, 'supplierName' => 'Acme']));

    expect($r->suggestedQty)->toBe(12)                 // 4 x (14 + 7 + 7) - 100
        ->and($r->explanation['reorder']['order_cycle_days'])->toBe(7)
        ->and($r->explanation['reorder']['order_cycle_source'])->toBe('supplier')
        ->and($r->explanation['reorder']['order_cycle_supplier'])->toBe('Acme');
});

it('only sells through a discontinued product: no reorder, overstock or lost sales', function () {
    // 4/day, 5 out-of-stock days recently, far more stock than needed.
    $r = calc(input(history(120, fn ($ago) => $ago <= 5 ? ['sold' => 0, 'in_stock' => false] : 4), stock: 600, extra: ['discontinued' => true]));

    expect($r->avgDailySales)->toBe(4.0)
        ->and($r->stockoutDate)->toBe('2027-02-17')    // 600 / 4 = 150 days: still shown
        ->and($r->reorderDate)->toBeNull()
        ->and($r->reorderPoint)->toBe(0)
        ->and($r->suggestedQty)->toBe(0)
        ->and($r->targetStock)->toBe(0)
        ->and($r->excessUnits)->toBe(0)
        ->and($r->lostUnits30d)->toBe(0.0)
        ->and($r->explanation['discontinued'])->toBeTrue();

    $codes = array_column((new ExplanationFormatter)->lines($r->explanation), 'code');
    expect($codes)->toContain('discontinued_sells_through')
        ->not->toContain('runs_out')->not->toContain('overstock')->not->toContain('lost_sales');
});

it('explains a sold-out discontinued product', function () {
    $r = calc(input(history(60, 2), stock: 0, extra: ['discontinued' => true]));

    expect($r->suggestedQty)->toBe(0)->and($r->reorderDate)->toBeNull();
    expect((new ExplanationFormatter)->lines($r->explanation)[1]['code'])->toBe('discontinued_sold_out');
});

it('moves the order date back to the supplier order weekday', function () {
    // 4/day, 100 in stock: due 2026-09-24 (a Thursday). Orders go out on Mondays.
    $r = calc(input(history(120, 4), stock: 100, extra: ['orderWeekdays' => [1], 'supplierName' => 'Acme']));

    expect($r->reorderDate)->toBe('2026-09-21')
        ->and($r->explanation['reorder']['order_weekdays'])->toBe(['days' => [1], 'supplier' => 'Acme', 'due_date' => '2026-09-24']);
    $lines = (new ExplanationFormatter)->lines($r->explanation);
    expect(collect($lines)->firstWhere('code', 'order_weekday_moved')['params'])
        ->toBe(['supplier' => 'Acme', 'reorder_date' => '2026-09-21', 'due_date' => '2026-09-24']);

    // Due on an order day: unchanged, no extra line. Tuesdays + Thursdays.
    $same = calc(input(history(120, 4), stock: 100, extra: ['orderWeekdays' => [2, 4]]));
    expect($same->reorderDate)->toBe('2026-09-24')
        ->and(array_column((new ExplanationFormatter)->lines($same->explanation), 'code'))->not->toContain('order_weekday_moved');
});

it('orders today when the last order weekday before the due date has passed', function () {
    // Due 2026-09-22 (Tuesday); order day Monday 09-21 is after today (Sunday 09-20)... use Saturdays: last one 09-19 is past.
    $r = calc(input(history(120, 4), stock: 92, extra: ['orderWeekdays' => [6]]));

    expect($r->explanation['reorder']['order_weekdays']['due_date'])->toBe('2026-09-22')
        ->and($r->reorderDate)->toBe('2026-09-20');
});

it('adds an upcoming sales event to the reorder point, order, stock-out and reorder dates', function () {
    // 4/day, 200 in stock; Sep 25-29 sells x2 (+20 units, inside the 21-day lead + safety window).
    $event = ['name' => 'Promo', 'from' => '2026-09-25', 'to' => '2026-09-29', 'multiplier' => 2.0];
    $r = calc(input(history(120, 4), stock: 200, extra: ['events' => [$event]]));

    expect($r->avgDailySales)->toBe(4.0)
        ->and($r->reorderPoint)->toBe(104)            // 84 + 20
        ->and($r->targetStock)->toBe(224)             // 204 + 20
        ->and($r->suggestedQty)->toBe(24)
        ->and($r->stockoutDate)->toBe('2026-11-04')   // 45 days instead of 50
        ->and($r->reorderDate)->toBe('2026-10-14')    // instead of Oct 19
        ->and($r->explanation['events']['upcoming'])->toBe([
            ['name' => 'Promo', 'from' => '2026-09-25', 'to' => '2026-09-29', 'multiplier' => 2.0, 'days' => 5, 'units_lead' => 20.0, 'units_order' => 20.0],
        ]);
    expect(collect((new ExplanationFormatter)->lines($r->explanation))->firstWhere('code', 'event_upcoming')['params'])
        ->toMatchArray(['name' => 'Promo', 'count' => 20, 'multiplier' => 2]);

    // Events that ended, or lie beyond the order window, change nothing ahead.
    $far = calc(input(history(120, 4), stock: 200, extra: ['events' => [['name' => 'Xmas', 'from' => '2027-06-01', 'to' => '2027-06-05', 'multiplier' => 3.0]]]));
    expect($far->reorderPoint)->toBe(84)->and($far->explanation['events']['upcoming'])->toBe([]);
});

it('lowers demand for an event below 1 (a closure)', function () {
    $r = calc(input(history(120, 4), stock: 200, extra: ['events' => [['name' => 'Closed', 'from' => '2026-09-20', 'to' => '2026-09-26', 'multiplier' => 0.5]]]));

    expect($r->reorderPoint)->toBe(70)   // 84 - 7 days x 2
        ->and(collect((new ExplanationFormatter)->lines($r->explanation))->pluck('code'))->toContain('event_upcoming_lower');
});

it('counts past event days at their normal level', function () {
    // Normally 4/day; a x2 promotion 10-14 days ago sold 8/day.
    $days = history(120, fn ($ago) => $ago >= 10 && $ago <= 14 ? 8 : 4);
    $event = ['name' => 'Summer sale', 'from' => '2026-09-06', 'to' => '2026-09-10', 'multiplier' => 2.0];

    expect(calc(input($days))->avgDailySales)->toBeGreaterThan(4.3);

    $r = calc(input($days, extra: ['events' => [$event]]));
    expect($r->avgDailySales)->toBe(4.0)
        ->and($r->explanation['events']['past'][0])->toMatchArray(['days' => 5, 'units_removed' => 20.0]);
    expect(collect((new ExplanationFormatter)->lines($r->explanation))->firstWhere('code', 'event_past')['params'])
        ->toMatchArray(['name' => 'Summer sale', 'count' => 5]);
});
