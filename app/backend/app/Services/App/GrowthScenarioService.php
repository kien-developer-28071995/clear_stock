<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Services\Forecast\ForecastCalculator;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/**
 * Growth what-if: "if sales change by X%, what do I need to order?". Every product's current
 * sales rate is multiplied by (1 + X%) and run through the forecast's own reorder maths
 * (lead time, safety days, min/max, minimum order, packs unchanged). Nothing is saved.
 */
class GrowthScenarioService
{
    /** Products returned in the table; totals always cover every product. */
    private const ITEM_LIMIT = 200;

    public function __construct(
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly ForecastCalculator $calculator,
        private readonly SalesEventService $salesEvents,
    ) {}

    /**
     * @param  array{supplier_id?: ?int, vendor?: ?string, abc?: ?string}  $filters
     */
    public function simulate(Shop $shop, int $growthPercent, int $horizonDays, array $filters = [], ?int $limit = self::ITEM_LIMIT): array
    {
        Entitlements::for($shop)->require(Feature::WhatIf);

        $factor = 1 + $growthPercent / 100;
        $today = CarbonImmutable::now($shop->timezone)->toDateString();
        $until = CarbonImmutable::parse($today)->addDays($horizonDays)->toDateString();

        $totals = ['now' => $this->emptyTotals(), 'scenario' => $this->emptyTotals()];
        $items = [];

        $events = $this->salesEvents->matcher($shop);
        foreach ($this->forecasts->planningRows($shop, $filters) as $row) {
            $row['events'] = $events->for($row['variant_id'], $row['supplier_id']);
            $now = $this->side($row, $row['avg'], $today, $until);
            $scenario = $this->side($row, round($row['avg'] * $factor, 2), $today, $until);
            if (! $now['due'] && ! $scenario['due']) {
                continue;
            }

            $this->add($totals['now'], $now, $row['unit_cost']);
            $this->add($totals['scenario'], $scenario, $row['unit_cost']);
            $items[] = [
                'variant_id' => $row['variant_id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'supplier' => $row['supplier'],
                'unit_cost' => $row['unit_cost'],
                'lead_time_days' => $row['lead_time_days'],
                'now' => $this->public($now),
                'scenario' => $this->public($scenario),
            ];
        }

        // Earliest order first (in the scenario), then by name.
        usort($items, fn ($a, $b) => [$a['scenario']['order_date'] ?? '9999', $a['name']] <=> [$b['scenario']['order_date'] ?? '9999', $b['name']]);

        return [
            'growth_percent' => $growthPercent,
            'factor' => round($factor, 2),
            'horizon_days' => $horizonDays,
            'today' => $today,
            'until' => $until,
            'currency' => $shop->currency,
            'totals' => array_map(fn ($t) => ['cost' => round($t['cost'], 2)] + $t, $totals),
            'items' => $limit === null ? $items : array_slice($items, 0, $limit),
            'items_total' => count($items),
        ];
    }

    /**
     * The scenario's orders as a purchase order CSV (Starter: needs PO export too): every product
     * due within the horizon with the order date and quantity of the scenario.
     *
     * @return array{filename: string, rows: array<int, array<int, string|int|float|null>>}
     */
    public function export(Shop $shop, int $growthPercent, int $horizonDays, array $filters = []): array
    {
        Entitlements::for($shop)->require(Feature::PurchaseOrders);
        $data = $this->simulate($shop, $growthPercent, $horizonDays, $filters, null);

        $rows = [['Order date', 'Supplier', 'Product', 'SKU', 'Sells per day (scenario)', 'Order quantity', 'Now: order quantity', 'Unit cost', 'Line total', 'Currency']];
        foreach ($data['items'] as $i) {
            $s = $i['scenario'];
            if (($s['order_qty'] ?? 0) <= 0 || $s['order_date'] === null || $s['order_date'] > $data['until']) {
                continue;
            }
            $rows[] = [
                $s['order_date'], $i['supplier'] ?? '', $i['name'], $i['sku'] ?? '', $s['avg'], $s['order_qty'], $i['now']['order_qty'],
                $i['unit_cost'], $i['unit_cost'] !== null ? round($i['unit_cost'] * $s['order_qty'], 2) : null, $shop->currency,
            ];
        }
        $sign = $growthPercent >= 0 ? 'plus' : 'minus';

        return ['filename' => "what-if-{$sign}".abs($growthPercent)."-{$data['today']}.csv", 'rows' => $rows];
    }

    /**
     * One side of the comparison. The order quantity is what is ordered on the order date:
     * today the gap from the current stock position, later the gap from the reorder point
     * (where the stock position will be by then, without new deliveries).
     */
    private function side(array $row, float $avg, string $today, string $until): array
    {
        $asOf = CarbonImmutable::parse($row['as_of']);
        $args = [$row['lead_time_days'], $row['safety_days'], $row['min_stock'], $row['max_stock'], $row['min_order_qty'], $row['pack_size'], $row['order_cycle_days'] ?? null, $row['order_weekdays'] ?? null, $row['events'] ?? []];
        $plan = $this->calculator->reorderPlan($asOf, $avg, $row['stock'], $row['incoming'], ...$args);

        $date = $plan['reorder_date'];
        $dueToday = $date !== null && $date <= $today;
        $due = $date !== null && $date <= $until;
        $qty = match (true) {
            ! $due => 0,
            $dueToday => $plan['suggested'],
            default => $this->calculator->reorderPlan($asOf, $avg, $plan['point'], 0, ...$args)['suggested'],
        };
        // Would run out before an order placed today arrives.
        $arrival = $asOf->addDays($row['lead_time_days'])->toDateString();

        return [
            'avg' => $avg,
            'order_date' => $date,
            'stockout_date' => $plan['stockout_date'],
            'order_qty' => $qty,
            'due' => $due && $qty > 0,
            'due_today' => $dueToday && $qty > 0,
            'stockout_risk' => $due && $avg > 0 && $plan['stockout_date'] !== null && $plan['stockout_date'] < $arrival,
        ];
    }

    private function public(array $side): array
    {
        return array_intersect_key($side, array_flip(['avg', 'order_date', 'stockout_date', 'order_qty', 'stockout_risk']));
    }

    private function emptyTotals(): array
    {
        return ['products' => 0, 'order_today' => 0, 'units' => 0, 'cost' => 0.0, 'missing_cost' => 0, 'stockout_risk' => 0];
    }

    private function add(array &$totals, array $side, ?float $unitCost): void
    {
        if (! $side['due']) {
            return;
        }
        $totals['products']++;
        $totals['order_today'] += $side['due_today'] ? 1 : 0;
        $totals['units'] += $side['order_qty'];
        $totals['stockout_risk'] += $side['stockout_risk'] ? 1 : 0;
        if ($unitCost === null) {
            $totals['missing_cost']++;
        } else {
            $totals['cost'] += $unitCost * $side['order_qty'];
        }
    }
}
