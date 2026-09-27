<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\ManualOrderRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Support\Entitlements;
use App\Support\Features;
use Carbon\CarbonImmutable;

/**
 * Monthly purchasing budget (Starter): when cash is short, what to reorder first. Everything due
 * now is ranked (out of stock, then runs out before a new order could arrive, then ABC class, then
 * the earliest stock-out) and fills the budget left this month; the rest waits. Orders marked as
 * placed this month (manual or emailed) count as spent. Every product gets the reason for its rank.
 */
class BudgetService
{
    public function __construct(
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly ManualOrderRepositoryInterface $orders,
        private readonly ShopRepositoryInterface $shops,
    ) {}

    public function build(Shop $shop): array
    {
        Entitlements::for($shop)->require(Feature::OrderBudget);

        $now = CarbonImmutable::now($shop->timezone);
        $today = $now->toDateString();
        $monthStart = $now->startOfMonth()->toDateString();
        $budget = $shop->order_budget !== null ? (float) $shop->order_budget : null;
        $spent = $this->orders->spentSince($shop, $monthStart);
        $useAbc = Features::enabled(Feature::Abc);

        $items = $this->forecasts->reorderList($shop, $today, null)->map(function (Forecast $f) use ($today, $useAbc) {
            $lead = (int) ($f->explanation['lead_time']['days'] ?? 0);
            $stockout = $f->stockout_date?->toDateString();
            $cost = $f->variant->unit_cost !== null ? (float) $f->variant->unit_cost : null;
            $reason = match (true) {
                $f->current_stock <= 0 => 'out_of_stock',
                $stockout !== null && $stockout <= CarbonImmutable::parse($today)->addDays($lead)->toDateString() => 'runs_out_before_delivery',
                default => 'reorder_point',
            };

            return [
                'variant_id' => $f->variant_id,
                'name' => $f->variant->displayName(),
                'sku' => $f->variant->sku,
                'supplier_id' => $f->variant->supplier_id,
                'supplier' => $f->variant->supplier?->name,
                'abc_class' => $useAbc ? $f->variant->abc_class : null,
                'stockout_date' => $stockout,
                'quantity' => $f->suggested_qty,
                'unit_cost' => $cost,
                'cost' => $cost !== null ? round($cost * $f->suggested_qty, 2) : null,
                'reason' => $reason,
            ];
        })->all();

        $rank = ['out_of_stock' => 0, 'runs_out_before_delivery' => 1, 'reorder_point' => 2];
        $abc = ['A' => 0, 'B' => 1, 'C' => 2];
        usort($items, fn ($a, $b) => [$rank[$a['reason']], $abc[$a['abc_class']] ?? 3, $a['stockout_date'] ?? '9999', $a['name']]
            <=> [$rank[$b['reason']], $abc[$b['abc_class']] ?? 3, $b['stockout_date'] ?? '9999', $b['name']]);

        // Fill what is left of the budget in that order; a product that does not fit waits, a cheaper one after it may still fit.
        $left = $budget !== null ? max(0.0, $budget - $spent['cost']) : null;
        $totals = ['products' => count($items), 'cost' => 0.0, 'in_budget' => 0, 'in_budget_cost' => 0.0, 'waiting' => 0, 'waiting_cost' => 0.0, 'missing_cost' => 0];
        foreach ($items as $i => &$item) {
            $item['priority'] = $i + 1;
            $cost = $item['cost'];
            $totals['cost'] += $cost ?? 0;
            if ($cost === null) {
                $totals['missing_cost']++;
            }
            $item['in_budget'] = $left === null || $cost === null || $cost <= $left + 1e-9;
            if ($item['in_budget']) {
                $totals['in_budget']++;
                $totals['in_budget_cost'] += $cost ?? 0;
                if ($left !== null && $cost !== null) {
                    $left -= $cost;
                }
            } else {
                $totals['waiting']++;
                $totals['waiting_cost'] += $cost;
            }
        }
        unset($item);

        return [
            'today' => $today,
            'month' => $now->format('Y-m'),
            'currency' => $shop->currency,
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $budget !== null ? round(max(0.0, $budget - $spent['cost']), 2) : null,
            'totals' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totals),
            'items' => $items,
        ];
    }

    public function setBudget(Shop $shop, ?float $budget): array
    {
        Entitlements::for($shop)->require(Feature::OrderBudget);

        return $this->build($this->shops->update($shop, ['order_budget' => $budget]));
    }
}
