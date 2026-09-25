<?php

namespace App\Services\Sync;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Support\Gid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the orders bulk result into daily totals per variant (shop timezone)
 * and replaces the sales of every day in the window. Orders themselves are
 * never stored.
 *
 * - units_sold     = LineItem.quantity (incl. later refunded/removed units)
 * - units_returned = quantity - currentQuantity (refunded or removed units)
 * - cancelled orders are ignored
 * - native bundles: component lines are counted as component sales (Shopify
 *   already splits them); the bundle's own sales come from lineItemGroup.
 */
class OrderAggregator
{
    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly DailySalesRepositoryInterface $sales,
    ) {}

    /** @return array{orders: int, line_items: int, rows: int} */
    public function import(Shop $shop, ?string $path, string $windowStart): array
    {
        $variantIds = $this->catalog->variantIdMap($shop);

        // Pass 1: order id => local date (null when cancelled). Children may precede parents
        // in the JSONL, so line items are aggregated in a second pass.
        $orderDay = [];
        foreach ($path ? JsonlReader::read($path) : [] as $line) {
            if (! isset($line['__parentId'])) {
                $orderDay[Gid::id($line['id'])] = $line['cancelledAt'] === null
                    ? Carbon::parse($line['processedAt'])->setTimezone($shop->timezone)->toDateString()
                    : null;
            }
        }

        // Pass 2: line items.
        $totals = [];       // variants.id => date => [sold, returned]
        $groupsSeen = [];   // "orderId|groupId" => true (a bundle counts once per order)
        $lineItems = 0;

        foreach ($path ? JsonlReader::read($path) : [] as $line) {
            if (! isset($line['__parentId'])) {
                continue;
            }
            $orderId = Gid::id($line['__parentId']);
            $day = $orderDay[$orderId] ?? null;
            if ($day === null || $day < $windowStart) {
                continue;
            }
            $lineItems++;

            $variant = $variantIds[Gid::id($line['variant']['id'] ?? null)] ?? null;
            if ($variant !== null) {
                $qty = (int) $line['quantity'];
                $current = (int) ($line['currentQuantity'] ?? $qty);
                $totals[$variant][$day][0] = ($totals[$variant][$day][0] ?? 0) + $qty;
                $totals[$variant][$day][1] = ($totals[$variant][$day][1] ?? 0) + max(0, $qty - $current);
            }

            $group = $line['lineItemGroup'] ?? null;
            if ($group !== null && ! isset($groupsSeen[$orderId.'|'.$group['id']])) {
                $groupsSeen[$orderId.'|'.$group['id']] = true;
                $bundle = $variantIds[Gid::id($group['variantId'] ?? null)] ?? null;
                if ($bundle !== null) {
                    $totals[$bundle][$day][0] = ($totals[$bundle][$day][0] ?? 0) + (int) $group['quantity'];
                    $totals[$bundle][$day][1] ??= 0;
                }
            }
        }

        $rows = [];
        foreach ($totals as $variant => $days) {
            foreach ($days as $date => [$sold, $returned]) {
                $rows[] = ['variant_id' => $variant, 'date' => $date, 'units_sold' => $sold, 'units_returned' => $returned];
            }
        }

        // Reset + refill atomically so a forecast never reads a half-imported window.
        DB::transaction(function () use ($shop, $windowStart, $rows) {
            $this->sales->resetSalesFrom($shop, $windowStart);
            $this->sales->upsertSales($shop, $rows);
        });

        return ['orders' => count($orderDay), 'line_items' => $lineItems, 'rows' => count($rows)];
    }
}
