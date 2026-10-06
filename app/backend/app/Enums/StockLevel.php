<?php

namespace App\Enums;

/** Where a variant's live stock stands against its forecast (real-time alerts). */
enum StockLevel: string
{
    case Ok = 'ok';
    /** Stock position (on hand + incoming) at or below the reorder point. */
    case Low = 'low';
    /** Nothing left on hand. */
    case Out = 'out';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Low => 1,
            self::Out => 2,
        };
    }

    public function alertType(): ?AlertType
    {
        return match ($this) {
            self::Ok => null,
            self::Low => AlertType::ReorderNeeded,
            self::Out => AlertType::OutOfStock,
        };
    }
}
