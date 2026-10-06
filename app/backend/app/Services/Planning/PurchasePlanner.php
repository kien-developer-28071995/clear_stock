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
    /**
     * Days after $day on which no order can be due, given the plan made on $day says the reorder
     * point is reached on $dueDate. Kept short of that date by: a week when the supplier has order
     * weekdays (the order moves back to the last of them), the days one unit of rounding is worth at this rate
     * (the walk rounds the stock down each day) and a day of margin.
     */
    private function quietDays(CarbonImmutable $day, string $dueDate, float $avg, bool $orderWeekdays): int
    {
        return (int) $day->diffInDays(CarbonImmutable::parse($dueDate)) - ($orderWeekdays ? 7 : 0) - 1 - (int) ceil(1 / $avg);
    }

    public function orders(array $row, string $from, string $until): array
    {
        $avg = (float) $row['avg'];
        if ($avg <= 0 && $row['min_stock'] === null) {
            return [];
        }
        $args = [$row['lead_time_days'], $row['safety_days'], $row['min_stock'], $row['max_stock'], $row['min_order_qty'], $row['pack_size'], $row['order_cycle_days'] ?? null, $row['order_weekdays'] ?? null, $row['events'] ?? []];

        $events = $row['events'] ?? [];
        $day = CarbonImmutable::parse($row['as_of']);
        $position = (float) ($row['stock'] + max(0, $row['incoming']));
        $orders = [];
        for (; ($date = $day->toDateString()) < $until; $day = $day->addDay()) {
            $plan = $this->calculator->reorderPlan($day, $avg, (int) floor($position), 0, ...$args);
            $ordered = $date >= $from && $plan['reorder_date'] === $date && $plan['suggested'] > 0;
            if ($ordered) {
                $orders[] = ['date' => $date, 'qty' => $plan['suggested']];
                $position += $plan['suggested'];
            }
            $position = max(0.0, $position - $avg * $this->calculator->multiplier($events, $date));

            // Nothing can be ordered for a while: jump over those days instead of asking every day
            // (a plan for thousands of products took seconds). Only at a steady rate (no sales events).
            if ($ordered || $events !== [] || $plan['due_date'] === null) {
                continue;
            }
            if ($avg <= 0) {
                if ($date >= $from) {
                    break;      // no sales and above the minimum: the stock position never changes again
                }

                continue;
            }
            $skip = min($this->quietDays($day, $plan['due_date'], $avg, ($row['order_weekdays'] ?? null) !== null), (int) $day->diffInDays(CarbonImmutable::parse($until)) - 1);
            if ($skip > 0) {
                // The same subtraction, day after day, as the walk would do: one multiplication
                // rounds differently and can move an order by a unit.
                for ($i = 0; $i < $skip; $i++) {
                    $position = max(0.0, $position - $avg);
                }
                $day = $day->addDays($skip);
            }
        }

        return $orders;
    }
}
