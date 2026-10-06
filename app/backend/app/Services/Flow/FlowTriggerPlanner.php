<?php

namespace App\Services\Flow;

use Carbon\CarbonImmutable;

/**
 * Decides which Shopify Flow triggers fire, from the forecasts and what each trigger saw last
 * time. Pure (no database, no API): each trigger fires once per change, never every night.
 *
 *  - Product reorder date reached: the reorder date is today or past (and there is something
 *    to order). Fires again only after the product stopped being due (ordered, stock arrived).
 *  - Product stockout threshold reached: days until stock-out crossed 30, 14, 7 or 0 (out of
 *    stock). Fires for each lower threshold; re-arms when stock recovers clearly above one.
 *  - Supplier reorder date reached: a product of the supplier became due. Lists every due product.
 */
final class FlowTriggerPlanner
{
    public const PRODUCT_REORDER = 'product-reorder-date-reached';

    public const PRODUCT_STOCKOUT = 'product-stockout-threshold-reached';

    public const SUPPLIER_REORDER = 'supplier-reorder-date-reached';

    /** Days until stock-out that fire the stock-out trigger (0 = out of stock now). */
    public const THRESHOLDS = [30, 14, 7, 0];

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  ForecastQueryRepository::planningRows()
     * @param  array<int, array{reorder_due?: bool, stockout_level?: ?int}>  $variantStates
     * @param  array<int, array{due?: array<int, int>}>  $supplierStates
     * @return array{events: array<int, array{handle: string, subject: string, subject_id: int, row?: array, rows?: array, new?: int, level?: int, days?: int}>,
     *     variant_states: array<int, array>, supplier_states: array<int, array>}
     */
    public function plan(iterable $rows, array $variantStates, array $supplierStates, string $today): array
    {
        $events = [];
        $variants = [];
        $dueBySupplier = [];

        foreach ($rows as $row) {
            $id = $row['variant_id'];
            $prev = $variantStates[$id] ?? [];
            $due = $row['reorder_date'] !== null && $row['reorder_date'] <= $today && $row['suggested_qty'] > 0;

            if ($due && ! ($prev['reorder_due'] ?? false)) {
                $events[] = ['handle' => self::PRODUCT_REORDER, 'subject' => 'variant', 'subject_id' => $id, 'row' => $row];
            }

            [$level, $days] = $this->stockoutLevel($row, $today);
            $prevLevel = $prev['stockout_level'] ?? null;
            if ($level !== null && ($prevLevel === null || $level < $prevLevel)) {
                $events[] = ['handle' => self::PRODUCT_STOCKOUT, 'subject' => 'variant', 'subject_id' => $id, 'row' => $row, 'level' => $level, 'days' => $days];
                $newLevel = $level;
            } else {
                $newLevel = $this->rearm($prevLevel, $level, $days);
            }

            $variants[$id] = ['reorder_due' => $due, 'stockout_level' => $newLevel];
            if ($due && $row['supplier_id'] !== null) {
                $dueBySupplier[$row['supplier_id']][] = $row;
            }
        }

        $suppliers = [];
        foreach ($dueBySupplier as $supplierId => $due) {
            $ids = array_map(fn ($r) => $r['variant_id'], $due);
            $new = array_diff($ids, $supplierStates[$supplierId]['due'] ?? []);
            if ($new !== []) {
                $events[] = ['handle' => self::SUPPLIER_REORDER, 'subject' => 'supplier', 'subject_id' => $supplierId, 'rows' => $due, 'new' => count($new)];
            }
            sort($ids);
            $suppliers[$supplierId] = ['due' => $ids];
        }
        // Suppliers with nothing due anymore start over.
        foreach (array_keys($supplierStates) as $supplierId) {
            $suppliers[$supplierId] ??= ['due' => []];
        }

        return ['events' => $events, 'variant_states' => $variants, 'supplier_states' => $suppliers];
    }

    /** @return array{0: ?int, 1: ?int} lowest threshold reached and days until stock-out */
    private function stockoutLevel(array $row, string $today): array
    {
        if ($row['avg'] <= 0 || $row['stockout_date'] === null) {
            return [null, null];
        }
        $days = $row['stock'] <= 0 ? 0 : max(0, (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($row['stockout_date']), false));
        $level = null;
        foreach (self::THRESHOLDS as $threshold) {
            if ($days <= $threshold) {
                $level = $threshold;
            }
        }

        return [$level, $days];
    }

    /**
     * After a restock the trigger may fire again, but only once stock is clearly above the last
     * threshold (20% + 2 days), so a product hovering around 7 days does not fire every night.
     */
    private function rearm(?int $prevLevel, ?int $level, ?int $days): ?int
    {
        if ($prevLevel === null) {
            return $level;
        }
        $clearlyAbove = $days === null || $days > $prevLevel * 1.2 + 2;

        return $clearlyAbove ? $level : $prevLevel;
    }
}
