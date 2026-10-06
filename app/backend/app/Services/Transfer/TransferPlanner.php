<?php

namespace App\Services\Transfer;

/**
 * Which stock to move between locations instead of ordering more (Growth). Pure logic on
 * the per-location forecasts of one product:
 *
 * - A location **needs** stock when it sells and its stock position (on hand + on the way)
 *   is at or below its reorder point: it needs enough to get back to its order-up-to level.
 * - A location **can spare** what it holds above the level it should keep (its order-up-to
 *   level, or nothing if it doesn't sell there), counting what is already on its way to it,
 *   and never more than it physically has.
 * - Needs are served most urgent first (earliest stock-out), from the locations with the most
 *   to spare. Quantities already in recent draft transfers count as moved.
 */
class TransferPlanner
{
    /**
     * @param  array<int, array{location_id: int, current_stock: int, incoming_stock: int, avg_daily_sales: float, reorder_point: int, target_stock: int, stockout_date: ?string, days_of_cover: ?float}>  $locations  one product's location forecasts
     * @param  array<int, array{origin: int, destination: int, quantity: int}>  $drafted  this product's quantities in recent draft transfers
     * @return array<int, array{origin: int, destination: int, quantity: int}>
     */
    public function plan(array $locations, array $drafted = []): array
    {
        $inDraftTo = [];
        $inDraftFrom = [];
        foreach ($drafted as $d) {
            $inDraftTo[$d['destination']] = ($inDraftTo[$d['destination']] ?? 0) + $d['quantity'];
            $inDraftFrom[$d['origin']] = ($inDraftFrom[$d['origin']] ?? 0) + $d['quantity'];
        }

        $needs = [];
        $spare = [];
        foreach ($locations as $l) {
            $id = $l['location_id'];
            $position = $l['current_stock'] + $l['incoming_stock'];
            $sells = $l['avg_daily_sales'] > 0;
            $keep = $sells ? max($l['target_stock'], $l['reorder_point']) : 0;

            if ($sells && $position <= $l['reorder_point']) {
                $need = $keep - $position - ($inDraftTo[$id] ?? 0);
                if ($need > 0) {
                    $needs[] = ['location' => $l, 'need' => $need];
                }

                continue;
            }

            $available = min($l['current_stock'], $position - $keep) - ($inDraftFrom[$id] ?? 0);
            if ($available > 0) {
                $spare[$id] = $available;
            }
        }

        if ($needs === [] || $spare === []) {
            return [];
        }

        // Most urgent first: earliest stock-out, then fewest days of cover.
        usort($needs, fn ($a, $b) => [$a['location']['stockout_date'] ?? '9999-12-31', $a['location']['days_of_cover'] ?? INF]
            <=> [$b['location']['stockout_date'] ?? '9999-12-31', $b['location']['days_of_cover'] ?? INF]);

        $moves = [];
        foreach ($needs as $n) {
            $need = $n['need'];
            arsort($spare);
            foreach ($spare as $origin => $available) {
                if ($need <= 0) {
                    break;
                }
                $quantity = min($need, $available);
                if ($quantity <= 0) {
                    continue;
                }
                $moves[] = ['origin' => $origin, 'destination' => $n['location']['location_id'], 'quantity' => $quantity];
                $spare[$origin] -= $quantity;
                $need -= $quantity;
            }
        }

        return $moves;
    }
}
