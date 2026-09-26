<?php

namespace App\Services\Forecast;

use App\Enums\Confidence;

final readonly class ForecastResult
{
    public function __construct(
        public int $variantId,
        public int $currentStock,
        public float $avgDailySales,
        public ?float $daysOfCover,   // null = no sales, stock lasts indefinitely
        public ?string $stockoutDate,
        public ?string $reorderDate,
        public int $reorderPoint,
        public int $suggestedQty,
        public Confidence $confidence,
        public array $explanation,
    ) {}
}
