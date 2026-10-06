<?php

namespace App\Repositories\Eloquent;

use App\Models\InventorySnapshot;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\InventorySnapshotRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentInventorySnapshotRepository implements InventorySnapshotRepositoryInterface
{
    public function record(Shop $shop, string $date, array $stock): void
    {
        $units = 0;
        $value = 0.0;
        $inStock = 0;
        $missingCost = 0;
        // Only id + cost, in chunks: a shop can have tens of thousands of variants.
        Variant::query()->forShop($shop)->where('is_active', true)->where('tracked', true)
            ->select(['id', 'unit_cost'])->toBase()->orderBy('id')
            ->chunk(2000, function ($rows) use ($stock, &$units, &$value, &$inStock, &$missingCost) {
                foreach ($rows as $row) {
                    $onHand = $stock[$row->id] ?? 0;
                    if ($onHand <= 0) {
                        continue; // oversold stock is not inventory
                    }
                    $units += $onHand;
                    $inStock++;
                    if ($row->unit_cost === null) {
                        $missingCost++;
                    } else {
                        $value += $onHand * (float) $row->unit_cost;
                    }
                }
            });

        DB::table('inventory_snapshots')->upsert([[
            'shop_id' => $shop->id, 'date' => $date, 'units' => $units, 'value' => round($value, 2),
            'products_in_stock' => $inStock, 'products_missing_cost' => $missingCost, 'created_at' => now(), 'updated_at' => now(),
        ]], ['shop_id', 'date'], ['units', 'value', 'products_in_stock', 'products_missing_cost', 'updated_at']);
    }

    public function since(Shop $shop, string $from): array
    {
        return InventorySnapshot::query()->forShop($shop)->where('date', '>=', $from)->orderBy('date')->get()
            ->map(fn (InventorySnapshot $s) => [
                'date' => $s->date->toDateString(),
                'units' => $s->units,
                'value' => (float) $s->value,
                'products_in_stock' => $s->products_in_stock,
                'products_missing_cost' => $s->products_missing_cost,
            ])->all();
    }

    public function prune(Shop $shop, string $before): int
    {
        return DB::table('inventory_snapshots')->where('shop_id', $shop->id)->where('date', '<', $before)->delete();
    }
}
