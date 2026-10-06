<?php

namespace App\Services\Forecast;

/**
 * Pure: how close past forecasts came to what really sold. For each product, the sales rate
 * forecast at the start of a week is compared with the real rate over the following days,
 * counting in-stock days only (as the forecast does). Products that neither were forecast to
 * sell nor sold are left out: they say nothing about accuracy.
 *
 *  accuracy = 1 - sum|forecast units - sold units| / sum sold units   (1 - WAPE, floored at 0)
 *  bias     = (sum forecast units - sum sold units) / sum sold units   (+ = forecast too high)
 */
final class AccuracyReport
{
    public function __construct(private readonly int $horizonDays, private readonly int $minInStockDays) {}

    public static function fromConfig(): self
    {
        return new self((int) config('forecast.accuracy.horizon_days'), (int) config('forecast.accuracy.min_in_stock_days'));
    }

    /**
     * @param  array<int, array{variant_id: int, name: string, sku: ?string, predicted: float, source: string, units: int, oos_days: int}>  $rows
     * @return array{products: int, forecast_units: float, actual_units: int, accuracy: ?float, bias: ?float,
     *     by_source: array<string, array{products: int, accuracy: ?float, bias: ?float}>, items: array<int, array>}
     */
    public function summarize(array $rows): array
    {
        $items = [];
        foreach ($rows as $r) {
            $item = $this->item($r);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $bySource = [];
        foreach (collect($items)->groupBy('source') as $source => $group) {
            $bySource[$source] = ['products' => $group->count()] + $this->scores($group->all());
        }
        // Biggest misses first (in units over the period).
        usort($items, fn ($a, $b) => [abs($b['error_units']), $a['variant_id']] <=> [abs($a['error_units']), $b['variant_id']]);

        return [
            'products' => count($items),
            'forecast_units' => round(array_sum(array_column($items, 'forecast_units')), 1),
            'actual_units' => array_sum(array_column($items, 'actual_units')),
        ] + $this->scores($items) + ['by_source' => $bySource, 'items' => $items];
    }

    /** One product, or null when it cannot be judged (too few in-stock days, nothing forecast or sold). */
    public function item(array $r): ?array
    {
        $inStock = $this->horizonDays - $r['oos_days'];
        if ($inStock < $this->minInStockDays || ($r['predicted'] <= 0 && $r['units'] <= 0)) {
            return null;
        }
        $forecastUnits = round($r['predicted'] * $inStock, 1);

        return [
            'variant_id' => $r['variant_id'],
            'name' => $r['name'],
            'sku' => $r['sku'],
            'source' => $r['source'],
            'in_stock_days' => $inStock,
            'predicted' => round($r['predicted'], 2),
            'actual' => round($r['units'] / $inStock, 2),
            'forecast_units' => $forecastUnits,
            'actual_units' => $r['units'],
            'error_units' => round($forecastUnits - $r['units'], 1),
        ];
    }

    /** @return array{accuracy: ?float, bias: ?float} */
    private function scores(array $items): array
    {
        $actual = array_sum(array_column($items, 'actual_units'));
        if ($actual <= 0) {
            return ['accuracy' => null, 'bias' => null];
        }
        $absError = array_sum(array_map(fn ($i) => abs($i['error_units']), $items));
        $forecast = array_sum(array_column($items, 'forecast_units'));

        return [
            'accuracy' => round(max(0.0, 1 - $absError / $actual), 3),
            'bias' => round(($forecast - $actual) / $actual, 3),
        ];
    }
}
