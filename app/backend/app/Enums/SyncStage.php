<?php

namespace App\Enums;

/** Ordered stages of a sync run with the progress (%) reached when the stage starts. */
enum SyncStage: string
{
    case Queued = 'queued';
    case Fetching = 'fetching';               // Shopify bulk operations running
    case ImportingCatalog = 'importing_catalog';
    case ImportingInventory = 'importing_inventory';
    case ImportingOrders = 'importing_orders';
    case RebuildingStock = 'rebuilding_stock';
    case Completed = 'completed';
    case Failed = 'failed';

    public function startProgress(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Fetching => 5,
            self::ImportingCatalog => 60,
            self::ImportingInventory => 70,
            self::ImportingOrders => 78,
            self::RebuildingStock => 92,
            self::Completed => 100,
            self::Failed => 0,
        };
    }
}
