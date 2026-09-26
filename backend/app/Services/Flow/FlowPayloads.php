<?php

namespace App\Services\Flow;

use App\Models\Shop;
use Carbon\CarbonImmutable;

/**
 * Trigger payloads. Keys must match the field keys in extensions/flow-* /shopify.extension.toml
 * (letters and spaces only); `product_id` is the product_reference field.
 */
final class FlowPayloads
{
    /** Order lines listed in the supplier trigger ("SKU · name × qty"), to stay far below 50 KB. */
    private const MAX_LINES = 100;

    public function productReorder(Shop $shop, array $row): array
    {
        return $this->product($shop, $row) + [
            'Suggested quantity' => $row['suggested_qty'],
            'Reorder date' => (string) $row['reorder_date'],
            'Days until stockout' => $this->daysUntil($row),
            'Lead time days' => $row['lead_time_days'],
            'Confidence' => $row['confidence'],
        ];
    }

    public function productStockout(Shop $shop, array $row, int $threshold, int $days): array
    {
        return $this->product($shop, $row) + [
            'Threshold days' => $threshold,
            'Days until stockout' => $days,
            'Suggested quantity' => $row['suggested_qty'],
            'Reorder date' => (string) $row['reorder_date'],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows every due product of the supplier */
    public function supplierReorder(Shop $shop, array $rows, int $new): array
    {
        $first = $rows[0];
        $lines = array_map(fn ($r) => trim(($r['sku'] ? "{$r['sku']} · " : '').$r['name']).' × '.$r['suggested_qty'], array_slice($rows, 0, self::MAX_LINES));
        if (count($rows) > self::MAX_LINES) {
            $lines[] = '+ '.(count($rows) - self::MAX_LINES).' more';
        }
        $costed = array_filter($rows, fn ($r) => $r['unit_cost'] !== null);

        return [
            'Supplier name' => (string) $first['supplier'],
            'Supplier email' => (string) ($first['supplier_email'] ?? ''),
            'Lead time days' => $first['supplier_lead_time_days'] ?? $first['lead_time_days'],
            'Products due' => count($rows),
            'New products due' => $new,
            'Total units' => array_sum(array_column($rows, 'suggested_qty')),
            'Total cost' => round(array_sum(array_map(fn ($r) => $r['unit_cost'] * $r['suggested_qty'], $costed)), 2),
            'Currency' => (string) $shop->currency,
            'Order lines' => implode('; ', $lines),
            'App URL' => $this->appUrl($shop, '/reorder'),
        ];
    }

    private function product(Shop $shop, array $row): array
    {
        return [
            'product_id' => $row['shopify_product_id'],
            'Variant ID' => (string) $row['shopify_variant_id'],
            'Product name' => $row['name'],
            'SKU' => (string) ($row['sku'] ?? ''),
            'Current stock' => $row['stock'],
            'Incoming stock' => $row['incoming'],
            'Sales per day' => $row['avg'],
            'Stockout date' => (string) ($row['stockout_date'] ?? ''),
            'Supplier name' => (string) ($row['supplier'] ?? ''),
            'Supplier email' => (string) ($row['supplier_email'] ?? ''),
            'ABC class' => (string) ($row['abc_class'] ?? ''),
            'App URL' => $this->appUrl($shop, "/products/{$row['variant_id']}"),
        ];
    }

    /** -1 when the product is not selling (no stock-out date). */
    private function daysUntil(array $row): int
    {
        if ($row['stockout_date'] === null) {
            return -1;
        }

        // Counted from the forecast's own date, like the stock-out date itself.
        return $row['stock'] <= 0 ? 0 : max(0, (int) CarbonImmutable::parse($row['as_of'])->diffInDays(CarbonImmutable::parse($row['stockout_date']), false));
    }

    private function appUrl(Shop $shop, string $path): string
    {
        return "https://{$shop->domain}/admin/apps/".config('shopify.api_key').$path;
    }
}
