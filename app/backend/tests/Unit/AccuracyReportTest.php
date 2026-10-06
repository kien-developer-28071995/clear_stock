<?php

use App\Services\Forecast\AccuracyReport;

function accuracyRow(int $id, float $predicted, int $units, int $oos = 0, string $source = 'computed'): array
{
    return ['variant_id' => $id, 'name' => "P{$id}", 'sku' => null, 'predicted' => $predicted, 'source' => $source, 'units' => $units, 'oos_days' => $oos];
}

it('scores forecasts against what sold on in-stock days (1 - WAPE) and reports the bias', function () {
    $s = (new AccuracyReport(28, 14))->summarize([
        accuracyRow(1, 5, 112),          // forecast 140, sold 112: +28
        accuracyRow(2, 2, 56),           // exact
    ]);

    expect($s['products'])->toBe(2)
        ->and($s['forecast_units'])->toEqual(196)
        ->and($s['actual_units'])->toBe(168)
        ->and($s['accuracy'])->toBe(0.833)       // 1 - 28 / 168
        ->and($s['bias'])->toBe(0.167)           // forecast 17% too high
        ->and($s['items'][0])->toMatchArray(['variant_id' => 1, 'predicted' => 5.0, 'actual' => 4.0, 'error_units' => 28.0]);
});

it('counts in-stock days only and skips products that cannot be judged', function () {
    $s = (new AccuracyReport(28, 14))->summarize([
        accuracyRow(1, 4, 80, oos: 8),   // 20 in-stock days: forecast 80, sold 80
        accuracyRow(2, 3, 30, oos: 20),  // 8 in-stock days: too few
        accuracyRow(3, 0, 0),            // forecast nothing, sold nothing
    ]);

    expect($s['products'])->toBe(1)->and($s['accuracy'])->toEqual(1.0)->and($s['bias'])->toEqual(0.0)
        ->and($s['items'][0]['in_stock_days'])->toBe(20);
});

it('floors accuracy at zero, has no score without sales and splits by source', function () {
    $s = (new AccuracyReport(28, 14))->summarize([
        accuracyRow(1, 10, 28),                      // forecast 280, sold 28
        accuracyRow(2, 1, 28, source: 'override'),   // exact
    ]);

    expect($s['accuracy'])->toEqual(0.0)
        ->and($s['by_source']['computed'])->toMatchArray(['products' => 1, 'accuracy' => 0.0])
        ->and($s['by_source']['override'])->toMatchArray(['products' => 1, 'accuracy' => 1.0]);

    expect((new AccuracyReport(28, 14))->summarize([accuracyRow(1, 2, 0)]))->toMatchArray(['products' => 1, 'accuracy' => null, 'bias' => null]);
});
