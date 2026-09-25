<?php

namespace App\Enums;

enum AlertType: string
{
    /** Variant reached its reorder point. */
    case ReorderNeeded = 'reorder_needed';
    /** Variant is out of stock. */
    case OutOfStock = 'out_of_stock';
    /** Digest email summarising the above. */
    case Digest = 'digest';
}
