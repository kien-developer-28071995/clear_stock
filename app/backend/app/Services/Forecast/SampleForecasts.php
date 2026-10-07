<?php

namespace App\Services\Forecast;

use App\Models\Forecast;
use App\Models\Shop;
use App\Support\ForecastStatusResolver;
use Carbon\CarbonImmutable;

/**
 * Forecasts of a made-up catalog, for a shop that has none of its own yet (first import still
 * running, or nothing to forecast). The sales history is invented; everything else is the real
 * thing: the same calculator, the shop's own lead time and safety days, today's date. Nothing
 * is stored.
 */
final class SampleForecasts
{
    private const HISTORY_DAYS = 120;

    /**
     * key => what the product is like. `rate` is units per day, oldest => newest (a list = a
     * trend across the history); `out` = days ago it was out of stock.
     */
    private const PRODUCTS = [
        'linen_shirt' => ['sku' => 'LS-SAND-M', 'rate' => [3.9], 'stock' => 50, 'out' => [18, 19, 20]],
        'canvas_tote' => ['sku' => 'CT-NAT', 'rate' => [6.2], 'stock' => 0, 'out' => [1, 2]],
        'wool_beanie' => ['sku' => 'WB-GRY', 'rate' => [1.2, 3.4], 'stock' => 70, 'out' => [], 'minOrderQty' => 24, 'packSize' => 12],
        'ceramic_mug' => ['sku' => 'CM-WHT', 'rate' => [2.1], 'stock' => 90, 'out' => []],
        'scented_candle' => ['sku' => 'SC-CEDAR', 'rate' => [0.2], 'stock' => 180, 'out' => []],
    ];

    public function __construct(private readonly ForecastCalculator $calculator, private readonly ExplanationFormatter $formatter) {}

    /** @return array<int, array<string, mixed>> */
    public function for(Shop $shop): array
    {
        $asOf = CarbonImmutable::now($shop->timezone)->startOfDay();
        $rows = [];

        foreach (array_keys(self::PRODUCTS) as $i => $key) {
            $p = self::PRODUCTS[$key];
            $r = $this->calculator->calculate(new ForecastInput(
                variantId: $i + 1,
                asOf: $asOf,
                coverageStart: $asOf->subDays(self::HISTORY_DAYS)->toDateString(),
                currentStock: $p['stock'],
                days: $this->history($asOf, $p['rate'], $p['out']),
                shopLeadTimeDays: $shop->default_lead_time_days,
                shopSafetyDays: $shop->default_safety_days,
                minOrderQty: $p['minOrderQty'] ?? null,
                packSize: $p['packSize'] ?? null,
            ));

            // The status rules live in one place; they read a forecast row.
            $forecast = (new Forecast)->forceFill([
                'current_stock' => $r->currentStock,
                'avg_daily_sales' => $r->avgDailySales,
                'days_of_cover' => $r->daysOfCover,
                'reorder_date' => $r->reorderDate,
                'target_stock' => $r->targetStock,
                'excess_units' => $r->excessUnits,
            ]);

            $rows[] = [
                'key' => $key,
                'sku' => $p['sku'],
                'status' => ForecastStatusResolver::for($forecast, $asOf->toDateString())->value,
                'current_stock' => $r->currentStock,
                'avg_daily_sales' => $r->avgDailySales,
                'days_of_cover' => $r->daysOfCover,
                'stockout_date' => $r->stockoutDate,
                'reorder_date' => $r->reorderDate,
                'suggested_qty' => $r->suggestedQty,
                'confidence' => $r->confidence->value,
                'explanation_lines' => $this->formatter->lines($r->explanation),
            ];
        }

        return $rows;
    }

    /**
     * Whole units per day that add up to the rate, the same on every call.
     *
     * @param  array<int, float>  $rate
     * @param  array<int, int>  $out
     * @return array<string, array{sold: int, returned: int, in_stock: bool}>
     */
    private function history(CarbonImmutable $asOf, array $rate, array $out): array
    {
        $from = $rate[0];
        $to = $rate[count($rate) - 1];
        $days = [];
        $total = 0.0;

        for ($ago = self::HISTORY_DAYS; $ago >= 1; $ago--) {
            $before = (int) floor($total);
            $total += $from + ($to - $from) * (self::HISTORY_DAYS - $ago) / (self::HISTORY_DAYS - 1);
            $inStock = ! in_array($ago, $out, true);
            $days[$asOf->subDays($ago)->toDateString()] = ['sold' => $inStock ? (int) floor($total) - $before : 0, 'returned' => 0, 'in_stock' => $inStock];
        }

        return $days;
    }
}
