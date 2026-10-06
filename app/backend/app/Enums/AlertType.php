<?php

namespace App\Enums;

enum AlertType: string
{
    /** Variant reached its reorder point. */
    case ReorderNeeded = 'reorder_needed';
    /** Variant has fewer days of stock left than the merchant's threshold (reorder date not reached yet). */
    case LowCover = 'low_cover';
    /** Variant is out of stock. */
    case OutOfStock = 'out_of_stock';
    /** Digest email summarising the above. */
    case Digest = 'digest';
    /** Real-time email (Growth) sent when stock crossed a threshold. */
    case Realtime = 'realtime';

    /** How bad a product alert is: a product is reported again when it gets worse. */
    public function severity(): int
    {
        return match ($this) {
            self::LowCover => 1,
            self::ReorderNeeded => 2,
            self::OutOfStock => 3,
            default => 0,
        };
    }
}
