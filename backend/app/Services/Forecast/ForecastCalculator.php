<?php

namespace App\Services\Forecast;

use App\Enums\Confidence;
use App\Enums\OverrideField;
use Carbon\CarbonImmutable;

/**
 * Pure forecast maths for one variant. No database access: every number it
 * produces is also written to the explanation, so the merchant can check it.
 *
 *  1. Daily demand = units sold - units returned (+ units sold through manual bundles).
 *     One-off spikes in the last 90 days (a wholesale order, a viral day) are capped to the usual level.
 *  2. Average per window (7/30/90 days) over IN-STOCK days only; weighted mix.
 *  3. Seasonality: last year's "next 28 days vs previous 28 days" ratio, when there is enough history
 *     and the change is bigger than ±10%.
 *  4. Merchant overrides win over computed values (average, lead time, safety days).
 *  5. Reorder point = average x (lead time + safety days).
 *     Suggested qty = average x (lead time + safety days + order cycle) - stock.
 *  6. Lost sales: the average x out-of-stock days of the last 30 days.
 *  7. Discontinued products (merchant no longer reorders them) keep the sell-through numbers
 *     (days of cover, stock-out date) but get no reorder point, order or overstock.
 */
class ForecastCalculator
{
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('forecast'));
    }

    public function calculate(ForecastInput $in): ForecastResult
    {
        $end = $in->asOf->subDay();                    // last complete day
        $series = $this->series($in, $end);
        $spikes = $this->capSpikes($series, $end, $in->filterSpikes);

        // --- 2. Windowed averages -------------------------------------------------
        $windows = [];
        foreach ($this->config['windows'] as $days => $cfg) {
            $windows[] = $this->window($series, $end, (int) $days, $cfg);
        }
        $usable = array_filter($windows, fn ($w) => $w['avg'] !== null);
        $weightSum = array_sum(array_column($usable, 'weight'));
        $baseAvg = 0.0;
        foreach ($windows as &$w) {
            $w['weight_applied'] = $w['avg'] !== null && $weightSum > 0 ? round($w['weight'] / $weightSum, 3) : 0.0;
            $baseAvg += $w['avg'] !== null ? $w['avg'] * ($w['weight'] / $weightSum) : 0;
        }
        unset($w);

        // --- 3. Seasonality ---------------------------------------------------------
        $seasonality = $this->seasonality($series, $in, $end);
        $computedAvg = $baseAvg * ($seasonality['applied'] ? $seasonality['factor'] : 1.0);

        // --- 3b. New product: blend in a similar product's rate -----------------------
        $reference = $this->reference($in, $series, $computedAvg);
        $estimate = $reference['applied'] ?? false ? $reference['avg'] : $computedAvg;

        // --- 4. Overrides -------------------------------------------------------------
        $avgOverride = $in->override(OverrideField::AvgDailySales);
        $avg = round($avgOverride !== null ? max(0.0, (float) $avgOverride['value']) : $estimate, 2);

        $leadTime = $this->leadTime($in);
        $safety = $this->safetyDays($in);

        // --- 5. Reorder maths -----------------------------------------------------------
        $plan = $this->reorderPlan($in->asOf, $avg, $in->currentStock, $in->incomingStock, $leadTime['days'], $safety['days'],
            $in->minStock, $in->maxStock, $in->minOrderQty, $in->packSize, $in->orderCycleDays);
        ['stock' => $stock, 'incoming' => $incoming, 'position' => $position, 'cycle' => $cycle, 'computed_point' => $computedPoint,
            'point' => $reorderPoint, 'target' => $targetStock, 'excess' => $excess, 'overstock' => $overstock, 'min' => $min, 'max' => $max,
            'rounding' => $rounding, 'suggested' => $suggested, 'days_of_cover' => $daysOfCover, 'stockout_date' => $stockoutDate,
            'reorder_date' => $reorderDate] = $plan;
        if ($in->orderRulesSupplier !== null) {
            $rounding['supplier'] = $in->orderRulesSupplier; // explained as the supplier's defaults
        }
        // Discontinued: the stock left sells through; nothing is reordered, held "too much" or missed.
        if ($in->discontinued) {
            [$reorderPoint, $targetStock, $excess, $overstock, $suggested, $reorderDate] = [0, 0, 0, false, 0, null];
            $rounding = ['needed' => 0, 'min_order_qty' => null, 'pack_size' => null, 'final' => 0];
        }

        $window30 = collect($windows)->firstWhere('days', 30);
        $lostDays = $in->discontinued ? 0 : (int) ($window30['oos_days'] ?? 0);
        $lostUnits = round($lostDays * $avg, 2);

        $confidence = $this->confidence($windows, $series, $end, $avgOverride !== null);
        if (($reference['applied'] ?? false) && $avgOverride === null) {
            $confidence['reasons'][] = ['code' => 'uses_reference'];
        }

        $explanation = [
            'version' => 1,
            'as_of' => $in->asOf->toDateString(),
            'history' => [
                'coverage_start' => $in->coverageStart,
                'days_available' => count($series),
            ],
            'windows' => array_map(fn ($w) => [
                'days' => $w['days'],
                'in_stock_days' => $w['in_stock_days'],
                'excluded_out_of_stock_days' => $w['oos_days'],
                'units' => $w['units'],
                'avg' => $w['avg'] !== null ? round($w['avg'], 2) : null,
                'weight' => $w['weight_applied'],
            ], $windows),
            'spikes' => $spikes,
            'base_avg' => round($baseAvg, 2),
            'seasonality' => $seasonality,
            'bundles' => $this->bundleContributions($in, $series, $end),
            'avg_daily_sales' => $avg,
            'avg_source' => $avgOverride !== null ? 'override' : (($reference['applied'] ?? false) ? 'reference' : 'computed'),
            'computed_avg' => round($estimate, 2),
            'own_avg' => round($computedAvg, 2),
            'reference' => $reference,
            'lead_time' => $leadTime,
            'safety' => $safety + ['units' => round($avg * $safety['days'], 1)],
            'stock' => ['current' => $stock, 'incoming' => $incoming, 'position' => $position],
            'discontinued' => $in->discontinued,
            'reorder' => [
                'lead_time_demand' => round($avg * $leadTime['days'], 1),
                'point' => $reorderPoint,
                'date' => $reorderDate,
                'order_cycle_days' => $cycle,
                'order_cycle_source' => $in->orderCycleDays !== null ? 'supplier' : 'default',
                'order_cycle_supplier' => $in->orderCycleDays !== null ? $in->supplierName : null,
                'suggested_qty' => $suggested,
                'computed_point' => $computedPoint,
                'target' => $targetStock,
                'excess' => $excess,
                'overstock' => $overstock,
                'min_stock' => $min,
                'max_stock' => $max,
                // What the supplier accepts: minimum order and whole packs (only when ordering).
                'rounding' => $rounding,
            ],
            'days_of_cover' => $daysOfCover,
            'stockout_date' => $stockoutDate,
            // Sales missed while out of stock (estimate: current average x out-of-stock days).
            'lost_sales' => ['days' => 30, 'out_of_stock_days' => $lostDays, 'units' => $lostUnits],
            'overrides' => array_map(
                fn ($field, $o) => ['field' => $field, 'value' => (float) $o['value'], 'note' => $o['note'] ?? null, 'expires_at' => $o['expires_at'] ?? null],
                array_keys($in->overrides),
                array_values($in->overrides),
            ),
            'confidence' => $confidence,
        ];

        return new ForecastResult(
            variantId: $in->variantId,
            currentStock: $stock,
            incomingStock: $incoming,
            avgDailySales: $avg,
            daysOfCover: $daysOfCover,
            stockoutDate: $stockoutDate,
            reorderDate: $reorderDate,
            reorderPoint: $reorderPoint,
            suggestedQty: $suggested,
            targetStock: $targetStock,
            excessUnits: $excess,
            lostUnits30d: $lostUnits,
            confidence: Confidence::from($confidence['level']),
            explanation: $explanation,
        );
    }

    /**
     * A new product borrows a similar product's rate (x percent) until it has its own history:
     * own weight = in-stock days / reference_full_after_days, the rest from the reference.
     *
     * @return ?array{variant_id: int, name: string, percent: int, applied: bool, reason?: string, reference_avg?: float, own_days?: int, own_weight?: float, avg?: float}
     */
    private function reference(ForecastInput $in, array $series, float $ownAvg): ?array
    {
        if ($in->reference === null) {
            return null;
        }
        $ref = $in->reference;
        $out = ['variant_id' => $ref['variant_id'], 'name' => $ref['name'], 'percent' => $ref['percent'], 'applied' => false];
        if ($ref['avg'] === null) {
            return $out + ['reason' => 'no_forecast'];
        }

        $ownDays = count(array_filter($series, fn ($d) => $d['in_stock']));
        $full = max(1, (int) ($this->config['reference_full_after_days'] ?? 30));
        if ($ownDays >= $full) {
            return $out + ['reason' => 'enough_history', 'own_days' => $ownDays];
        }

        $referenceAvg = $ref['avg'] * $ref['percent'] / 100;
        $ownWeight = round($ownDays / $full, 3);

        return array_merge($out, [
            'applied' => true,
            'reference_avg' => round($referenceAvg, 2),
            'own_days' => $ownDays,
            'own_weight' => $ownWeight,
            'avg' => $ownWeight * $ownAvg + (1 - $ownWeight) * $referenceAvg,
        ]);
    }

    /**
     * Step 5 on its own: reorder point, order-up-to level, suggested order and dates for a given
     * daily average. Also used by the growth what-if, so a scenario runs the exact same maths.
     *
     * Reordering looks at the stock position: on hand + already on the way. Shopify gives no
     * arrival date for incoming stock, so the stock-out date and days of cover use what is on hand only.
     *
     * @return array{stock: int, incoming: int, position: int, cycle: int, computed_point: int, point: int, target: int, excess: int,
     *     overstock: bool, min: ?int, max: ?int, rounding: array, suggested: int, days_of_cover: ?float, stockout_date: ?string, reorder_date: ?string}
     */
    public function reorderPlan(
        CarbonImmutable $asOf, float $avg, int $stock, int $incoming, int $leadTimeDays, int $safetyDays,
        ?int $minStock = null, ?int $maxStock = null, ?int $minOrderQty = null, ?int $packSize = null, ?int $orderCycleDays = null,
    ): array {
        $incoming = max(0, $incoming);
        $position = $stock + $incoming;
        $cycle = $orderCycleDays ?? (int) $this->config['order_cycle_days'];
        $computedPoint = (int) ceil($avg * ($leadTimeDays + $safetyDays) - 1e-9);
        $computedTarget = $avg * ($leadTimeDays + $safetyDays + $cycle);
        // Manual min/max (Stocky style) replace the computed reorder point / order-up-to level.
        $min = $minStock;
        $max = $maxStock !== null && $maxStock > 0 ? $maxStock : null;
        $reorderPoint = $min ?? ($max !== null ? min($computedPoint, $max) : $computedPoint);
        $target = $max ?? max($computedTarget, (float) $reorderPoint);
        $needed = max(0, (int) ceil($target - $position - 1e-9));
        // Overstock: what is held beyond the level the forecast (or the merchant's max) says to hold.
        $targetStock = (int) ceil($target - 1e-9);
        $excess = ($avg > 0 || $max !== null) ? max(0, $position - $targetStock) : 0;
        $overstock = $avg > 0 && $targetStock > 0 && $excess > $targetStock * (float) ($this->config['overstock_ratio'] ?? 0.5);
        $rounding = $this->roundOrder($needed, $minOrderQty, $packSize);

        if ($avg <= 0) {
            $daysOfCover = $stock <= 0 ? 0.0 : null;
            $stockoutDate = null;
            // Without sales only a manual minimum triggers a reorder.
            $reorderDate = $min !== null && $position <= $min ? $asOf->toDateString() : null;
        } else {
            $daysOfCover = $stock <= 0 ? 0.0 : round($stock / $avg, 1);
            $stockoutDate = $asOf->addDays($stock <= 0 ? 0 : (int) floor($stock / $avg))->toDateString();
            $reorderDate = $asOf->addDays($position <= $reorderPoint ? 0 : (int) floor(($position - $reorderPoint) / $avg))->toDateString();
        }

        return [
            'stock' => $stock, 'incoming' => $incoming, 'position' => $position, 'cycle' => $cycle,
            'computed_point' => $computedPoint, 'point' => $reorderPoint, 'target' => $targetStock,
            'excess' => $excess, 'overstock' => $overstock, 'min' => $min, 'max' => $max,
            'rounding' => $rounding, 'suggested' => $rounding['final'],
            'days_of_cover' => $daysOfCover, 'stockout_date' => $stockoutDate, 'reorder_date' => $reorderDate,
        ];
    }

    /**
     * Daily demand from coverage start (capped to history_days) to $end.
     *
     * @return array<string, array{demand: float, bundle: float, in_stock: bool}>
     */
    private function series(ForecastInput $in, CarbonImmutable $end): array
    {
        $start = max($in->coverageStart, $end->subDays((int) $this->config['history_days'] - 1)->toDateString());
        $series = [];

        for ($day = CarbonImmutable::parse($start); $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = $in->days[$date] ?? null;
            $own = $row ? max(0, $row['sold'] - $row['returned']) : 0;
            $bundle = 0;
            foreach ($in->bundles as $b) {
                $bundle += ($b['days'][$date] ?? 0) * $b['quantity'];
            }
            $series[$date] = ['demand' => (float) ($own + $bundle), 'bundle' => (float) $bundle, 'in_stock' => $row['in_stock'] ?? true];
        }

        return $series;
    }

    /**
     * Caps one-off spikes in the last `spikes.days` days: an in-stock day selling at least `min_units`
     * and more than `factor` x the average of the other in-stock days counts as that average instead.
     * Needs enough in-stock days with sales, so a product that rarely sells is left alone. When big
     * days come back often (a weekly wholesale customer) they are real demand: nothing is capped.
     *
     * @return ?array{applied: bool, factor: float, days: array<int, array{date: string, units: float, usual: float}>, units_removed: float}
     */
    private function capSpikes(array &$series, CarbonImmutable $end, bool $enabled): ?array
    {
        if (! $enabled) {
            return null;
        }
        $cfg = $this->config['spikes'];
        $from = $end->subDays((int) $cfg['days'] - 1)->toDateString();
        $days = array_filter($series, fn ($d, $date) => $date >= $from && $d['in_stock'], ARRAY_FILTER_USE_BOTH);
        $out = ['applied' => false, 'factor' => (float) $cfg['factor'], 'days' => [], 'units_removed' => 0.0];
        $selling = count(array_filter($days, fn ($d) => $d['demand'] > 0));
        if (count($days) < (int) $cfg['min_in_stock_days'] || $selling < (int) $cfg['min_selling_days']) {
            return $out;
        }

        $total = array_sum(array_column($days, 'demand'));
        $n = count($days);
        $found = [];
        foreach ($days as $date => $d) {
            $usual = ($total - $d['demand']) / ($n - 1);
            if ($d['demand'] >= $cfg['min_units'] && $usual > 0 && $d['demand'] > $cfg['factor'] * $usual) {
                $found[$date] = ['date' => $date, 'units' => $d['demand'], 'usual' => round($usual, 2)];
            }
        }
        if (count($found) > (int) $cfg['max_days']) {
            return array_merge($out, ['reason' => 'recurring', 'big_days' => count($found)]);
        }
        foreach ($found as $date => $spike) {
            $out['days'][] = $spike;
            $out['units_removed'] += $spike['units'] - $spike['usual'];
            $series[$date]['demand'] = $spike['usual'];
        }
        $out['applied'] = $out['days'] !== [];
        $out['units_removed'] = round($out['units_removed'], 1);
        // The biggest few are enough to explain it.
        usort($out['days'], fn ($a, $b) => $b['units'] <=> $a['units']);
        $out['days'] = array_slice($out['days'], 0, 5);

        return $out;
    }

    private function window(array $series, CarbonImmutable $end, int $days, array $cfg): array
    {
        $from = $end->subDays($days - 1)->toDateString();
        $inStock = 0;
        $oos = 0;
        $units = 0.0;

        foreach ($series as $date => $d) {
            if ($date < $from) {
                continue;
            }
            if ($d['in_stock']) {
                $inStock++;
                $units += $d['demand'];
            } else {
                $oos++;
            }
        }

        return [
            'days' => $days,
            'in_stock_days' => $inStock,
            'oos_days' => $oos,
            'units' => $units,
            'avg' => $inStock >= $cfg['min_in_stock_days'] ? $units / $inStock : null,
            'weight' => (float) $cfg['weight'],
        ];
    }

    /** Last year: average of the next N days vs the previous N days (same calendar dates). */
    private function seasonality(array $series, ForecastInput $in, CarbonImmutable $end): array
    {
        $cfg = $this->config['seasonality'];
        $n = (int) $cfg['horizon_days'];
        $lyToday = $in->asOf->subYear();
        $next = $this->period($series, $lyToday, $lyToday->addDays($n - 1));
        $prev = $this->period($series, $lyToday->subDays($n), $lyToday->subDay());

        $result = [
            'applied' => false,
            'factor' => 1.0,
            'horizon_days' => $n,
            'last_year' => [
                'previous_period' => ['from' => $lyToday->subDays($n)->toDateString(), 'to' => $lyToday->subDay()->toDateString(), 'units' => $prev['units'], 'avg' => $prev['avg']],
                'next_period' => ['from' => $lyToday->toDateString(), 'to' => $lyToday->addDays($n - 1)->toDateString(), 'units' => $next['units'], 'avg' => $next['avg']],
            ],
            'reason' => null,
        ];

        if ($prev['covered'] < $n || $next['covered'] < $n) {
            return ['reason' => 'not_enough_history'] + $result;
        }
        if ($prev['in_stock'] < $n * $cfg['min_in_stock_ratio'] || $next['in_stock'] < $n * $cfg['min_in_stock_ratio']) {
            return ['reason' => 'out_of_stock_last_year'] + $result;
        }
        if ($prev['units'] < $cfg['min_units'] || $prev['avg'] <= 0) {
            return ['reason' => 'too_few_sales_last_year'] + $result;
        }

        $raw = $next['avg'] / $prev['avg'];
        if (abs($raw - 1) < ($cfg['dead_band'] ?? 0)) {
            return array_merge($result, ['reason' => 'no_significant_change', 'raw_factor' => round($raw, 2)]);
        }
        $factor = round(min($cfg['max_factor'], max($cfg['min_factor'], $raw)), 2);

        return array_merge($result, [
            'applied' => true,
            'factor' => $factor,
            'raw_factor' => round($raw, 2),
            'clamped' => $factor !== round($raw, 2),
        ]);
    }

    /** @return array{covered: int, in_stock: int, units: float, avg: ?float} */
    private function period(array $series, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $covered = 0;
        $inStock = 0;
        $units = 0.0;
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $row = $series[$d->toDateString()] ?? null;
            if ($row === null) {
                continue;
            }
            $covered++;
            if ($row['in_stock']) {
                $inStock++;
                $units += $row['demand'];
            }
        }

        return ['covered' => $covered, 'in_stock' => $inStock, 'units' => $units, 'avg' => $inStock > 0 ? round($units / $inStock, 3) : null];
    }

    /** override > variant setting > supplier > shop default */
    private function leadTime(ForecastInput $in): array
    {
        if ($o = $in->override(OverrideField::LeadTimeDays)) {
            return ['days' => (int) round($o['value']), 'source' => 'override'];
        }
        if ($in->variantLeadTimeDays !== null) {
            return ['days' => $in->variantLeadTimeDays, 'source' => 'variant'];
        }
        if ($in->supplierLeadTimeDays !== null) {
            return ['days' => $in->supplierLeadTimeDays, 'source' => 'supplier', 'supplier' => $in->supplierName];
        }

        return ['days' => $in->shopLeadTimeDays, 'source' => 'shop_default'];
    }

    /** override > variant setting > shop default */
    private function safetyDays(ForecastInput $in): array
    {
        if ($o = $in->override(OverrideField::SafetyDays)) {
            return ['days' => (int) round($o['value']), 'source' => 'override'];
        }
        if ($in->variantSafetyDays !== null) {
            return ['days' => $in->variantSafetyDays, 'source' => 'variant'];
        }

        return ['days' => $in->shopSafetyDays, 'source' => 'shop_default'];
    }

    /** Units per day each manual bundle adds, over the in-stock days of the 30-day window. */
    private function bundleContributions(ForecastInput $in, array $series, CarbonImmutable $end): array
    {
        if ($in->bundles === []) {
            return [];
        }

        $from = $end->subDays(29)->toDateString();
        $inStockDates = array_keys(array_filter($series, fn ($d, $date) => $date >= $from && $d['in_stock'], ARRAY_FILTER_USE_BOTH));
        $days = max(1, count($inStockDates));

        $out = [];
        foreach ($in->bundles as $bundleId => $b) {
            $bundlesSold = array_sum(array_map(fn ($date) => $b['days'][$date] ?? 0, $inStockDates));
            $out[] = [
                'bundle_variant_id' => $bundleId,
                'name' => $b['name'],
                'quantity_per_bundle' => $b['quantity'],
                'bundles_sold_30d' => $bundlesSold,
                'units_per_day' => round($bundlesSold * $b['quantity'] / $days, 2),
            ];
        }

        return $out;
    }

    private function confidence(array $windows, array $series, CarbonImmutable $end, bool $avgOverridden): array
    {
        $cfg = $this->config['confidence'];
        $w90 = collect($windows)->firstWhere('days', 90) ?? end($windows);
        $inStockDays = $w90['in_stock_days'];
        $units = $w90['units'];
        $cv = $this->weeklyCv($series, $end);

        $reasons = [];
        if ($inStockDays < $cfg['low_in_stock_days']) {
            $reasons[] = ['code' => 'little_history', 'in_stock_days' => $inStockDays];
        }
        if ($units < $cfg['low_units']) {
            $reasons[] = ['code' => 'few_sales', 'units' => $units];
        }
        if ($cv !== null && $cv > $cfg['low_weekly_cv']) {
            $reasons[] = ['code' => 'volatile', 'weekly_cv' => $cv];
        }

        $level = match (true) {
            $reasons !== [] => 'low',
            $inStockDays >= $cfg['high_in_stock_days'] && $units >= $cfg['high_units'] && $cv !== null && $cv <= $cfg['high_weekly_cv'] => 'high',
            default => 'medium',
        };

        if ($avgOverridden) {
            $reasons[] = ['code' => 'average_overridden'];
        }

        return ['level' => $level, 'reasons' => $reasons, 'in_stock_days_90' => $inStockDays, 'units_90' => $units, 'weekly_cv' => $cv];
    }

    /**
     * Coefficient of variation of weekly demand rates over the last 90 days
     * (weeks with at least 4 in-stock days). Weekly buckets keep slow movers,
     * which sell every few days, from looking erratic.
     */
    private function weeklyCv(array $series, CarbonImmutable $end): ?float
    {
        $rates = [];
        for ($week = 0; $week < 13; $week++) {
            $inStock = 0;
            $units = 0.0;
            for ($d = 0; $d < 7; $d++) {
                $row = $series[$end->subDays($week * 7 + $d)->toDateString()] ?? null;
                if ($row !== null && $row['in_stock']) {
                    $inStock++;
                    $units += $row['demand'];
                }
            }
            if ($inStock >= 4) {
                $rates[] = $units / $inStock;
            }
        }

        if (count($rates) < 3) {
            return null;
        }
        $mean = array_sum($rates) / count($rates);
        if ($mean <= 0) {
            return null;
        }
        $variance = array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $rates)) / count($rates);

        return round(sqrt($variance) / $mean, 2);
    }

    /**
     * Raise an order to the minimum order quantity, then round up to whole packs.
     * Nothing to order stays nothing.
     *
     * @return array{needed: int, min_order_qty: ?int, pack_size: ?int, final: int}
     */
    private function roundOrder(int $needed, ?int $minOrderQty, ?int $packSize): array
    {
        $minOrderQty = $minOrderQty !== null && $minOrderQty > 1 ? $minOrderQty : null;
        $packSize = $packSize !== null && $packSize > 1 ? $packSize : null;

        $final = $needed;
        if ($final > 0 && $minOrderQty !== null) {
            $final = max($final, $minOrderQty);
        }
        if ($final > 0 && $packSize !== null) {
            $final = (int) (ceil($final / $packSize) * $packSize);
        }

        return ['needed' => $needed, 'min_order_qty' => $minOrderQty, 'pack_size' => $packSize, 'final' => $final];
    }
}
