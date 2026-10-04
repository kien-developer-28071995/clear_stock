<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Services\Planning\PurchasePlanner;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/**
 * Purchase plan: what to order and how much it costs, week by week over the next weeks, if every
 * product keeps selling at its forecast rate. Helps plan cash for stock. Nothing is saved.
 */
class PurchasePlanService
{
    /** Products listed; totals always cover every product. */
    private const ITEM_LIMIT = 200;

    private const CALENDAR_LIMIT = 100;

    public function __construct(
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly PurchasePlanner $planner,
        private readonly SalesEventService $salesEvents,
    ) {}

    /**
     * Every planned order as CSV rows (date, supplier, product, quantity, cost), to send ahead to
     * suppliers or plan cash in a spreadsheet. Needs the purchase plan and PO export.
     *
     * @param  array{supplier_id?: ?int, vendor?: ?string}  $filters
     * @return array{filename: string, rows: array<int, array<int, string|int|float|null>>}
     */
    public function export(Shop $shop, int $weeks, array $filters = []): array
    {
        Entitlements::for($shop)->require(Feature::PurchasePlan);
        Entitlements::for($shop)->require(Feature::PurchaseOrders);

        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $until = $today->addWeeks($weeks)->toDateString();

        $lines = [];
        $events = $this->salesEvents->matcher($shop);
        foreach ($this->forecasts->planningRows($shop, $filters) as $row) {
            $row['events'] = $events->for($row['variant_id'], $row['supplier_id']);
            foreach ($this->planner->orders($row, $today->toDateString(), $until) as $o) {
                $cost = $row['unit_cost'];
                $lines[] = [
                    $o['date'],
                    intdiv((int) $today->diffInDays(CarbonImmutable::parse($o['date'])), 7) + 1,
                    $row['supplier'] ?? '',
                    $row['name'],
                    $row['sku'] ?? '',
                    $o['qty'],
                    $cost,
                    $cost !== null ? round($cost * $o['qty'], 2) : null,
                    $shop->currency,
                ];
            }
        }
        usort($lines, fn ($a, $b) => [$a[0], $a[2], $a[3]] <=> [$b[0], $b[2], $b[3]]);

        return [
            'filename' => "purchase-plan-{$weeks}w-{$today->toDateString()}.csv",
            'rows' => [['Order date', 'Week', 'Supplier', 'Product', 'SKU', 'Order quantity', 'Unit cost', 'Line total', 'Currency'], ...$lines],
        ];
    }

    /** @param array{supplier_id?: ?int, vendor?: ?string} $filters */
    public function build(Shop $shop, int $weeks, array $filters = []): array
    {
        Entitlements::for($shop)->require(Feature::PurchasePlan);

        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $until = $today->addWeeks($weeks)->toDateString();

        $weekRows = [];
        for ($w = 0; $w < $weeks; $w++) {
            $weekRows[$w] = ['start' => $today->addWeeks($w)->toDateString(), 'orders' => 0, 'units' => 0, 'cost' => 0.0, 'missing_cost' => 0];
        }
        $suppliers = [];
        $calendar = [];
        $months = [];
        $items = [];
        $totals = ['products' => 0, 'orders' => 0, 'units' => 0, 'cost' => 0.0, 'missing_cost' => 0];

        $events = $this->salesEvents->matcher($shop);
        foreach ($this->forecasts->planningRows($shop, $filters) as $row) {
            $row['events'] = $events->for($row['variant_id'], $row['supplier_id']);
            $orders = $this->planner->orders($row, $today->toDateString(), $until);
            if ($orders === []) {
                continue;
            }
            $cost = $row['unit_cost'];
            $units = array_sum(array_column($orders, 'qty'));

            foreach ($orders as $o) {
                $m = substr($o['date'], 0, 7);
                $months[$m] ??= ['month' => $m, 'orders' => 0, 'units' => 0, 'cost' => 0.0, 'missing_cost' => 0];
                $months[$m]['orders']++;
                $months[$m]['units'] += $o['qty'];
                $cost === null ? $months[$m]['missing_cost']++ : $months[$m]['cost'] += $o['qty'] * $cost;
                $w = intdiv((int) $today->diffInDays(CarbonImmutable::parse($o['date'])), 7);
                $weekRows[$w]['orders']++;
                $weekRows[$w]['units'] += $o['qty'];
                $cost === null ? $weekRows[$w]['missing_cost']++ : $weekRows[$w]['cost'] += $o['qty'] * $cost;
            }

            // Order calendar: one entry per order day and supplier.
            foreach ($orders as $o) {
                $c = &$calendar[$o['date'].'|'.($row['supplier_id'] ?? 0)];
                $c ??= ['date' => $o['date'], 'supplier_id' => $row['supplier_id'], 'supplier' => $row['supplier'], 'products' => 0, 'units' => 0, 'cost' => 0.0, 'missing_cost' => 0];
                $c['products']++;
                $c['units'] += $o['qty'];
                $cost === null ? $c['missing_cost']++ : $c['cost'] += $o['qty'] * $cost;
                unset($c);
            }

            $key = $row['supplier_id'] ?? 0;
            $suppliers[$key] ??= ['supplier_id' => $row['supplier_id'], 'name' => $row['supplier'], 'products' => 0, 'orders' => 0, 'units' => 0, 'cost' => 0.0, 'missing_cost' => 0, 'first_order' => null];
            $s = &$suppliers[$key];
            $s['products']++;
            $s['orders'] += count($orders);
            $s['units'] += $units;
            $cost === null ? $s['missing_cost']++ : $s['cost'] += $units * $cost;
            $s['first_order'] = min($s['first_order'] ?? '9999', $orders[0]['date']);
            unset($s);

            $totals['products']++;
            $totals['orders'] += count($orders);
            $totals['units'] += $units;
            $cost === null ? $totals['missing_cost']++ : $totals['cost'] += $units * $cost;

            $items[] = [
                'variant_id' => $row['variant_id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'supplier' => $row['supplier'],
                'unit_cost' => $cost,
                'avg' => $row['avg'],
                'orders' => $orders,
                'units' => $units,
                'cost' => $cost !== null ? round($units * $cost, 2) : null,
            ];
        }

        // Biggest spend first (unknown cost last), then first order date.
        usort($items, fn ($a, $b) => [-($a['cost'] ?? -1), $a['orders'][0]['date'], $a['name']] <=> [-($b['cost'] ?? -1), $b['orders'][0]['date'], $b['name']]);
        $suppliers = array_values($suppliers);
        usort($suppliers, fn ($a, $b) => [-$a['cost'], $a['first_order']] <=> [-$b['cost'], $b['first_order']]);

        $money = fn (array $r) => ['cost' => round($r['cost'], 2)] + $r;
        ksort($months);
        $calendar = array_values($calendar);
        usort($calendar, fn ($a, $b) => [$a['date'], $a['supplier'] ?? "\u{10FFFF}"] <=> [$b['date'], $b['supplier'] ?? "\u{10FFFF}"]);

        return [
            'today' => $today->toDateString(),
            'until' => $until,
            'weeks' => $weeks,
            'currency' => $shop->currency,
            'totals' => $money($totals),
            'by_week' => array_map($money, array_values($weekRows)),
            'by_supplier' => array_map($money, $suppliers),
            // Spend per calendar month next to the monthly budget (Starter, null when none is set).
            'by_month' => array_map($money, array_values($months)),
            'budget' => Entitlements::for($shop)->has(Feature::OrderBudget) && $shop->order_budget !== null ? (float) $shop->order_budget : null,
            // When to order from whom (products without a supplier: supplier null, listed last on a day).
            // The next order days only: a large shop has thousands of day x supplier entries.
            'calendar' => array_map($money, array_slice($calendar, 0, self::CALENDAR_LIMIT)),
            'calendar_total' => count($calendar),
            'items' => array_slice($items, 0, self::ITEM_LIMIT),
            'items_total' => count($items),
        ];
    }
}
