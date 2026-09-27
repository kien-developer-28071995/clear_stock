<?php

namespace App\Services\Planning;

use App\Services\Forecast\ForecastCalculator;
use Carbon\CarbonImmutable;

/**
 * Pure: the orders one product needs over the next N days if it keeps selling at its current
 * rate. Walks day by day from the forecast date: whenever the stock position reaches the reorder
 * point it orders what the forecast would suggest (same maths: min/max, minimum order, packs,
 * order cycle), the order joins the stock position, and sales take the average off each day.
 */
class PurchasePlanner
{
    public function __construct(private readonly ForecastCalculator $calculator) {}

    /**
     * @param  array{as_of: string, avg: float, stock: int, incoming: int, lead_time_days: int, safety_days: int, min_stock: ?int,
     *     max_stock: ?int, min_order_qty: ?int, pack_size: ?int, order_cycle_days?: ?int}  $row  planning row of a combined forecast
     * @return array<int, array{date: string, qty: int}> orders placed from $from up to (not including) $until
     */
    public function orders(array $row, string $from, string $until): array
    {
        $avg = (float) $row['avg'];
        if ($avg <= 0 && $row['min_stock'] === null) {
            return [];
        }
        $args = [$row['lead_time_days'], $row['safety_days'], $row['min_stock'], $row['max_stock'], $row['min_order_qty'], $row['pack_size'], $row['order_cycle_days'] ?? null, $row['order_weekdays'] ?? null, $row['events'] ?? []];

        $day = CarbonImmutable::parse($row['as_of']);
        $position = (float) ($row['stock'] + max(0, $row['incoming']));
        $orders = [];
        for (; $day->toDateString() < $until; $day = $day->addDay()) {
            $plan = $this->calculator->reorderPlan($day, $avg, (int) floor($position), 0, ...$args);
            if ($day->toDateString() >= $from && $plan['reorder_date'] === $day->toDateString() && $plan['suggested'] > 0) {
                $orders[] = ['date' => $day->toDateString(), 'qty' => $plan['suggested']];
                $position += $plan['suggested'];
            }
            $position = max(0.0, $position - $avg * $this->calculator->multiplier($row['events'] ?? [], $day->toDateString()));
        }

        return $orders;
    }
}
