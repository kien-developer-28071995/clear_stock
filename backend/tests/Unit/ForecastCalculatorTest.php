<?php

use App\Enums\Confidence;
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
