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

    public function __construct(
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly PurchasePlanner $planner,
    ) {}

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
        $items = [];
        $totals = ['products' => 0, 'orders' => 0, 'units' => 0, 'cost' => 0.0, 'missing_cost' => 0];

        foreach ($this->forecasts->planningRows($shop, $filters) as $row) {
            $orders = $this->planner->orders($row, $today->toDateString(), $until);
            if ($orders === []) {
                continue;
            }
            $cost = $row['unit_cost'];
            $units = array_sum(array_column($orders, 'qty'));

            foreach ($orders as $o) {
                $w = intdiv((int) $today->diffInDays(CarbonImmutable::parse($o['date'])), 7);
                $weekRows[$w]['orders']++;
                $weekRows[$w]['units'] += $o['qty'];
                $cost === null ? $weekRows[$w]['missing_cost']++ : $weekRows[$w]['cost'] += $o['qty'] * $cost;
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

        return [
            'today' => $today->toDateString(),
            'until' => $until,
            'weeks' => $weeks,
            'currency' => $shop->currency,
            'totals' => $money($totals),
            'by_week' => array_map($money, array_values($weekRows)),
            'by_supplier' => array_map($money, $suppliers),
            'items' => array_slice($items, 0, self::ITEM_LIMIT),
            'items_total' => count($items),
        ];
    }
}
