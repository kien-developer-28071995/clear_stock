<?php

use App\Services\Forecast\ForecastCalculator;
use App\Services\Planning\PurchasePlanner;
use Carbon\CarbonImmutable;

/** The walk without any shortcut: asks the forecast maths on every single day. */
function everyDayOrders(ForecastCalculator $calc, array $row, string $from, string $until): array
{
    $args = [$row['lead_time_days'], $row['safety_days'], $row['min_stock'], $row['max_stock'], $row['min_order_qty'], $row['pack_size'], $row['order_cycle_days'], $row['order_weekdays'], []];
    $position = (float) ($row['stock'] + max(0, $row['incoming']));
    $orders = [];
    for ($day = CarbonImmutable::parse($row['as_of']); $day->toDateString() < $until; $day = $day->addDay()) {
        $plan = $calc->reorderPlan($day, $row['avg'], (int) floor($position), 0, ...$args);
        if ($day->toDateString() >= $from && $plan['reorder_date'] === $day->toDateString() && $plan['suggested'] > 0) {
            $orders[] = ['date' => $day->toDateString(), 'qty' => $plan['suggested']];
            $position += $plan['suggested'];
        }
        $position = max(0.0, $position - $row['avg']);
    }

    return $orders;
}

it('plans the same orders when it jumps over quiet days as when it asks every day', function () {
    $calc = new ForecastCalculator(require __DIR__.'/../../config/forecast.php');
    $planner = new PurchasePlanner($calc);
    mt_srand(7);

    for ($i = 0; $i < 400; $i++) {
        $row = [
            'as_of' => '2026-09-'.str_pad((string) mt_rand(14, 20), 2, '0', STR_PAD_LEFT),
            'avg' => [0.0, 0.03, 0.1, 0.4, 1.0, 2.5, 7.3, 40.0][mt_rand(0, 7)],
            'stock' => mt_rand(-5, 900), 'incoming' => mt_rand(0, 3) === 0 ? mt_rand(1, 200) : 0,
            'lead_time_days' => mt_rand(0, 60), 'safety_days' => mt_rand(0, 21),
            'min_stock' => mt_rand(0, 4) === 0 ? mt_rand(0, 80) : null, 'max_stock' => mt_rand(0, 5) === 0 ? mt_rand(80, 400) : null,
            'min_order_qty' => mt_rand(0, 3) === 0 ? mt_rand(2, 100) : null, 'pack_size' => mt_rand(0, 3) === 0 ? mt_rand(2, 24) : null,
            'order_cycle_days' => mt_rand(0, 2) === 0 ? mt_rand(7, 90) : null,
            'order_weekdays' => [null, null, [1], [2, 5], [7]][mt_rand(0, 4)],
        ];
        expect($planner->orders($row, '2026-09-20', '2026-12-13'))->toBe(everyDayOrders($calc, $row, '2026-09-20', '2026-12-13'), json_encode($row));
    }
});
