<?php

namespace App\Enums;

/** Filters of the SKU list; also shown as badges. */
enum ForecastStatus: string
{
    case ReorderNow = 'reorder_now';   // reorder date reached (includes out of stock)
    case OutOfStock = 'out_of_stock';  // no stock, still selling
    case Slow = 'slow';                // stock lasts longer than slow_mover_days, or never sells
    case Healthy = 'healthy';          // reorder date in the future
}
