<?php

namespace App\Services\Sync;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Repositories\Contracts\LocationSalesRepositoryInterface;
use App\Support\Features;
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
 * - cancelled orders are ignored, and so are orders the merchant excluded by tag or source
 * - native bundles: component lines are counted as component sales (Shopify
 *   already splits them); the bundle's own sales come from lineItemGroup.
 * - with locations (Growth): fulfillment orders give the location each unit is
 *   fulfilled from; cancelled fulfillment orders are ignored.
 */
class OrderAggregator
{
    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly DailySalesRepositoryInterface $sales,
        private readonly LocationSalesRepositoryInterface $locationSales,
    ) {}

    /** @return array{orders: int, excluded_orders: int, line_items: int, rows: int, location_rows: int} */
    public function import(Shop $shop, ?string $path, string $windowStart, bool $withLocations = false): array
    {
        $variantIds = $this->catalog->variantIdMap($shop);
        $locationIds = $withLocations ? $this->catalog->activeLocationIds($shop) : [];

        // Pass 1: order id => local date (null when cancelled), fulfillment order => [order, location].
        // Children may precede parents in the JSONL, so lines are aggregated in a second pass.
        $orderDay = [];
        $fulfillmentOrders = [];
        // Orders the merchant keeps out of the forecast (Settings): by tag or by source.
        $excluding = Features::on('order_exclusions');
        $tags = $excluding ? array_map('mb_strtolower', $shop->excluded_order_tags ?? []) : [];
        $sources = $excluding ? $shop->excluded_order_sources ?? [] : [];
        $excludedOrders = 0;
        $excluded = fn (array $order): bool => ($tags !== [] && array_intersect($tags, array_map('mb_strtolower', array_filter(is_array($order['tags'] ?? null) ? $order['tags'] : [], 'is_string'))) !== [])
            || ($sources !== [] && in_array(OrderSource::of(is_string($order['sourceName'] ?? null) ? $order['sourceName'] : null), $sources, true));
        // Units of one line as the export gives them: a whole number from 0 up to a sane ceiling.
        $units = fn (mixed $value): int => is_numeric($value) ? (int) max(0, min(1_000_000, (float) $value)) : 0;
        $gidOf = fn (mixed $node): mixed => is_array($node) ? ($node['id'] ?? null) : null;
        foreach ($path ? JsonlReader::read($path) : [] as $line) {
            if (! isset($line['__parentId'])) {
                $orderId = Gid::id($line['id'] ?? null);
                if ($orderId === null) {
                    continue; // not an order we can place
                }
                if ($excluded($line)) {
                    $orderDay[$orderId] = null;
                    $excludedOrders++;

                    continue;
                }
                $orderDay[$orderId] = ($line['cancelledAt'] ?? null) === null ? $this->localDay($line['processedAt'] ?? null, $shop->timezone) : null;
            } elseif (isset($line['id']) && Gid::type($line['id']) === 'FulfillmentOrder' && ($line['status'] ?? '') !== 'CANCELLED') {
                $assigned = is_array($line['assignedLocation'] ?? null) ? ($line['assignedLocation']['location'] ?? null) : null;
                $location = $locationIds[Gid::id($gidOf($assigned))] ?? null;
                if ($location !== null) {
                    $fulfillmentOrders[Gid::id($line['id'])] = [Gid::id($line['__parentId']), $location];
                }
            }
        }

        // Pass 2: line items.
        $totals = [];       // variants.id => date => [sold, returned]
        $byLocation = [];   // "variant|location|date" => units
        $groupsSeen = [];   // "orderId|groupId" => true (a bundle counts once per order)
        $lineItems = 0;

        foreach ($path ? JsonlReader::read($path) : [] as $line) {
            if (! isset($line['__parentId']) || isset($line['id'])) {
                continue; // orders and fulfillment orders themselves
            }

            if (Gid::type($line['__parentId']) === 'FulfillmentOrder') {
                [$orderId, $location] = $fulfillmentOrders[Gid::id($line['__parentId'])] ?? [null, null];
                $day = $orderDay[$orderId] ?? null;
                $variant = $variantIds[Gid::id($gidOf($line['variant'] ?? null))] ?? null;
                $quantity = $units($line['totalQuantity'] ?? 0);
                if ($location !== null && $day !== null && $day >= $windowStart && $variant !== null && $quantity > 0) {
                    $key = "{$variant}|{$location}|{$day}";
                    $byLocation[$key] = ($byLocation[$key] ?? 0) + $quantity;
                }

                continue;
            }

            $orderId = Gid::id($line['__parentId']);
            $day = $orderDay[$orderId] ?? null;
            if ($day === null || $day < $windowStart) {
                continue;
            }
            $lineItems++;

            $variant = $variantIds[Gid::id($gidOf($line['variant'] ?? null))] ?? null;
            if ($variant !== null) {
                $qty = $units($line['quantity'] ?? 0);
                $current = min($qty, $units($line['currentQuantity'] ?? $qty));
                $totals[$variant][$day][0] = ($totals[$variant][$day][0] ?? 0) + $qty;
                $totals[$variant][$day][1] = ($totals[$variant][$day][1] ?? 0) + max(0, $qty - $current);
            }

            $group = is_array($line['lineItemGroup'] ?? null) && is_string($line['lineItemGroup']['id'] ?? null) ? $line['lineItemGroup'] : null;
            if ($group !== null && ! isset($groupsSeen[$orderId.'|'.$group['id']])) {
                $groupsSeen[$orderId.'|'.$group['id']] = true;
                $bundle = $variantIds[Gid::id($group['variantId'] ?? null)] ?? null;
                if ($bundle !== null) {
                    $totals[$bundle][$day][0] = ($totals[$bundle][$day][0] ?? 0) + $units($group['quantity'] ?? 0);
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

        if ($withLocations) {
            $locationRows = [];
            foreach ($byLocation as $key => $units) {
                [$variant, $location, $date] = explode('|', $key);
                $locationRows[] = ['variant_id' => (int) $variant, 'location_id' => (int) $location, 'date' => $date, 'units_sold' => $units];
            }
            $this->locationSales->replaceFrom($shop, $windowStart, $locationRows);
        }

        return ['orders' => count($orderDay), 'excluded_orders' => $excludedOrders, 'line_items' => $lineItems, 'rows' => count($rows), 'location_rows' => count($byLocation)];
    }

    /** The shop's calendar day of an order, or null when the export gives no usable time for it. */
    private function localDay(mixed $processedAt, string $timezone): ?string
    {
        if (! is_string($processedAt) || $processedAt === '') {
            return null;
        }
        try {
            return Carbon::parse($processedAt)->setTimezone($timezone)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
