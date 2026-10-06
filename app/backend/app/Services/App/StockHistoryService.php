<?php

namespace App\Services\App;

use App\Models\Shop;
use App\Repositories\Contracts\InventorySnapshotRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * Inventory value over time (every plan): units and value at cost, one point per day since the
 * app started recording (Shopify keeps no stock history, so there is nothing to backfill).
 */
class StockHistoryService
{
    public function __construct(private readonly InventorySnapshotRepositoryInterface $snapshots) {}

    /** @return array{days: int, points: array, latest: ?array, change: ?array, started_on: ?string} */
    public function history(Shop $shop, int $days): array
    {
        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $points = $this->snapshots->since($shop, $today->subDays($days)->toDateString());
        $latest = $points === [] ? null : end($points);
        $first = $points[0] ?? null;

        return [
            'days' => $days,
            'points' => array_map(fn ($p) => ['date' => $p['date'], 'units' => $p['units'], 'value' => $p['value']], $points),
            'latest' => $latest,
            // First point of the period vs the latest (needs at least a week between them to mean something).
            'change' => $first !== null && $latest !== null && CarbonImmutable::parse($first['date'])->diffInDays(CarbonImmutable::parse($latest['date'])) >= 7 ? [
                'from' => $first['date'],
                'units' => $latest['units'] - $first['units'],
                'value' => round($latest['value'] - $first['value'], 2),
                'percent' => $first['value'] > 0 ? round(($latest['value'] - $first['value']) / $first['value'] * 100, 1) : null,
            ] : null,
            'started_on' => $first['date'] ?? null,
        ];
    }
}
