<?php

namespace App\Services\Forecast;

use App\Enums\Confidence;

final readonly class ForecastResult
{
    public function __construct(
        public int $variantId,
        public int $currentStock,
        public int $incomingStock,
        public float $avgDailySales,
        public ?float $daysOfCover,   // null = no sales, stock lasts indefinitely
        public ?string $stockoutDate,
        public ?string $reorderDate,
        public int $reorderPoint,
        public int $suggestedQty,
        public int $targetStock,     // order-up-to level (units)
        public int $excessUnits,     // stock + on the way above it
        public float $lostUnits30d,  // estimated units not sold on the out-of-stock days of the last 30 days
        public ?int $trendPercent,   // recent sales vs the weeks before, in percent (null = too little to tell)
        public Confidence $confidence,
        public array $explanation,
    ) {}
}
