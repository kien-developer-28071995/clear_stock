<?php

use App\Services\Forecast\ExplanationFormatter;
use App\Services\Forecast\ForecastCalculator;
use App\Services\Forecast\ForecastInput;
use Carbon\CarbonImmutable;

function explain(array $days, int $stock, array $extra = []): array
{
    $asOf = CarbonImmutable::parse('2026-09-20');
    $input = new ForecastInput(...array_merge([
        'variantId' => 1, 'asOf' => $asOf, 'coverageStart' => min(array_keys($days)), 'currentStock' => $stock,
        'days' => $days, 'shopLeadTimeDays' => 14, 'shopSafetyDays' => 7,
    ], $extra));

    $result = (new ForecastCalculator(require __DIR__.'/../../config/forecast.php'))->calculate($input);

    return (new ExplanationFormatter)->sentences($result->explanation);
}

function days(int $n, callable $f): array
{
    $out = [];
    for ($ago = 1; $ago <= $n; $ago++) {
        $out[CarbonImmutable::parse('2026-09-20')->subDays($ago)->toDateString()] = $f($ago);
    }

    return $out;
}

it('explains a typical forecast in plain language', function () {
    $d = days(120, fn ($ago) => in_array($ago, [2, 3, 4], true)
        ? ['sold' => 0, 'returned' => 0, 'in_stock' => false]
        : ['sold' => 4, 'returned' => 0, 'in_stock' => true]);

    expect(explain($d, 100))->toBe([
        'Sells 4/day over the last 30 days (3 out-of-stock days left out).',
        'Lead time 14 days (store default) + 7 safety days → reorder point 84 units.',
        '100 in stock runs out around Oct 15 → order 104 units by Sep 24.',
        'Out of stock 3 days in the last 30 days: about 12 sales missed.',
        'Confidence: high.',
    ]);
});

it('explains capped spikes and a supplier order cycle', function () {
    $d = days(120, fn ($ago) => ['sold' => $ago === 5 ? 60 : 4, 'returned' => 0, 'in_stock' => true]);

    $lines = explain($d, 100, ['filterSpikes' => true, 'orderCycleDays' => 14, 'supplierName' => 'Acme']);

    expect($lines)->toContain('Counted 1 one-off spike at the usual level (60 sold on Sep 15, usually 4/day).')
        ->and($lines)->toContain('Orders cover 14 days of sales: the order cycle of Acme.')
        ->and($lines[0])->toBe('Sells 4/day over the last 30 days.');
});

it('explains overrides, suppliers, bundles and low confidence', function () {
    $d = days(10, fn () => ['sold' => 1, 'returned' => 0, 'in_stock' => true]);

    $sentences = explain($d, 0, [
        'supplierName' => 'Acme', 'supplierLeadTimeDays' => 30,
        'overrides' => ['avg_daily_sales' => ['value' => 3, 'note' => 'Promo', 'expires_at' => null]],
        'bundles' => [9 => ['name' => 'Gift box', 'quantity' => 2, 'days' => array_map(fn () => 1, $d)]],
    ]);

    expect($sentences)->toContain('You set sales to 3/day (Promo); our estimate was 3/day.')
        ->toContain('Includes 2/day sold inside "Gift box" (2 per bundle).')
        ->toContain('Lead time 30 days (supplier Acme) + 7 safety days → reorder point 111 units.')
        ->toContain('Out of stock now → order 201 units today.')
        ->toContain('Confidence: low (only 10 days of in-stock history, rate set manually).');
});

it('says so plainly when a variant has not sold', function () {
    $d = days(120, fn () => ['sold' => 0, 'returned' => 0, 'in_stock' => true]);

    expect(explain($d, 40))->toBe([
        'No sales in the last 90 days while in stock.',
        'Confidence: low (no sales in 90 days).',
    ]);
});

it('only explains the blend when the displayed rates differ', function () {
    // 30-day and 90-day averages round to the same "2/day": no blend sentence.
    $d = days(120, fn ($ago) => ['sold' => $ago === 50 ? 3 : 2, 'returned' => 0, 'in_stock' => true]);

    expect(collect(explain($d, 500))->contains(fn ($s) => str_starts_with($s, 'Blended rate')))->toBeFalse();
});

it('gives the app codes and raw params, never text', function () {
    $d = days(10, fn () => ['sold' => 1, 'returned' => 0, 'in_stock' => true]);
    $asOf = CarbonImmutable::parse('2026-09-20');
    $result = (new ForecastCalculator(require __DIR__.'/../../config/forecast.php'))->calculate(new ForecastInput(
        variantId: 1, asOf: $asOf, coverageStart: min(array_keys($d)), currentStock: 0, days: $d,
        shopLeadTimeDays: 14, shopSafetyDays: 7, supplierName: 'Acme', supplierLeadTimeDays: 30,
    ));

    $lines = (new ExplanationFormatter)->lines($result->explanation);

    expect(collect($lines)->pluck('code')->all())->toBe(['sells_over_window', 'reorder_point', 'order_today_out_of_stock', 'confidence_with_reasons'])
        ->and($lines[1]['params'])->toMatchArray([
            'lead_days' => 30, 'safety_days' => 7,
            'lead_source' => ['code' => 'lead_source_supplier', 'params' => ['supplier' => 'Acme']],
        ])
        ->and($lines[3]['params']['level'])->toBe(['code' => 'confidence_low', 'params' => []])
        ->and($lines[3]['params']['reasons'][0])->toBe(['code' => 'reason_little_history', 'params' => ['count' => 10]]);
});
