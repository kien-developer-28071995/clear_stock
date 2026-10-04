<?php

namespace App\Services\App;

use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * Two reads of the forecasts for the Insights page (every plan):
 *  - clearance list: slow and overstocked products with their sell-through and last sale,
 *    to decide what to discount;
 *  - broken size runs: products where a best-selling variant is short while other variants sit.
 */
class StockInsightService
{
    private const SELL_THROUGH_DAYS = 90;

    /** A variant counts as a product's key seller from this share of the product's sales. */
    private const KEY_SHARE = 0.2;

    /** ... and as sitting stock from this many days of cover. */
    private const SITTING_DAYS = 90;

    public function __construct(private readonly ForecastQueryRepositoryInterface $forecasts) {}

    /** @return array{days: int, currency: ?string, count: int, value: float, items: array<int, array<string, mixed>>} */
    public function clearance(Shop $shop, int $limit): array
    {
        $today = CarbonImmutable::now($shop->timezone);
        $items = $this->forecasts->clearance($shop, $today->toDateString(), $today->subDays(self::SELL_THROUGH_DAYS)->toDateString(), $limit);

        return [
            'days' => self::SELL_THROUGH_DAYS,
            'currency' => $shop->currency,
            'count' => count($items),
            'value' => round(array_sum(array_map(fn ($i) => $i['value'] ?? 0, $items)), 2),
            'items' => array_map(fn ($i) => $i + [
                'days_since_sale' => $i['last_sold_on'] !== null ? (int) CarbonImmutable::parse($i['last_sold_on'], $shop->timezone)->diffInDays($today->startOfDay()) : null,
            ], $items),
        ];
    }

    /** @return \Generator<int, array<int, string|int|float|null>> CSV rows, header first */
    public function clearanceRows(Shop $shop): \Generator
    {
        yield ['Product', 'SKU', 'Why', 'In stock', 'Units above what to hold', 'Sold in 90 days', 'Sell-through %', 'Last sale', 'Days of stock', 'Stock value', 'Currency'];
        foreach ($this->clearance($shop, 5000)['items'] as $i) {
            yield [$i['name'], $i['sku'] ?? '', $i['status'], $i['stock'], $i['excess'], $i['sold'], $i['sell_through'] !== null ? round($i['sell_through'] * 100, 1) : null,
                $i['last_sold_on'] ?? '', $i['days_of_cover'], $i['value'], $shop->currency];
        }
    }

    /** @return array<int, array{product_id: int, product: string, short: int, sitting: int, variants: array<int, array<string, mixed>>}> */
    public function sizeRuns(Shop $shop, int $limit = 20): array
    {
        $today = CarbonImmutable::now($shop->timezone)->toDateString();
        $out = [];
        foreach ($this->forecasts->variantsByProduct($shop) as $productId => $variants) {
            $total = array_sum(array_column($variants, 'avg'));
            if (count($variants) < 3 || $total <= 0) {
                continue;
            }
            $rows = array_map(function (array $v) use ($total, $today) {
                $share = $v['avg'] / $total;
                $short = $v['avg'] > 0 && ($v['stock'] <= 0 || ($v['reorder_date'] !== null && $v['reorder_date'] <= $today));
                $sitting = $v['stock'] > 0 && ($v['avg'] <= 0 || ($v['days_of_cover'] ?? 0) > self::SITTING_DAYS);

                return $v + ['share' => round($share, 3), 'state' => match (true) {
                    $short && $share >= self::KEY_SHARE => 'short',
                    $sitting => 'sitting',
                    default => 'ok',
                }];
            }, $variants);
            $short = count(array_filter($rows, fn ($r) => $r['state'] === 'short'));
            $sitting = count(array_filter($rows, fn ($r) => $r['state'] === 'sitting'));
            if ($short === 0 || $sitting === 0) {
                continue;
            }
            usort($rows, fn ($a, $b) => $b['share'] <=> $a['share']);
            $out[] = ['product_id' => $productId, 'product' => $variants[0]['product'], 'short' => $short, 'sitting' => $sitting, 'sales' => round($total, 2),
                'variants' => array_map(fn ($r) => array_diff_key($r, ['product' => 1, 'reorder_date' => 1]), $rows)];
        }
        usort($out, fn ($a, $b) => $b['sales'] <=> $a['sales']);

        return array_slice($out, 0, $limit);
    }
}
