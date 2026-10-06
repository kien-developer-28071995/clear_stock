<?php

namespace App\Enums;

/** Which stock changes trigger a real-time email (Growth). */
enum RealtimeAlertMode: string
{
    case Off = 'off';
    /** Only when a product sells out. */
    case OutOfStock = 'out_of_stock';
    /** When a product reaches its reorder point, and again if it then sells out. */
    case All = 'all';
}
