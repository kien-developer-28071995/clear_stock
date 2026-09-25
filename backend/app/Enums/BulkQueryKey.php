<?php

namespace App\Enums;

/** The bulk operations a sync run submits (run concurrently; Shopify allows 5 per shop). */
enum BulkQueryKey: string
{
    case Variants = 'variants';
    case Inventory = 'inventory';
    case Orders = 'orders';
}
