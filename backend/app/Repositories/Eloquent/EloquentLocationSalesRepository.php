<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Repositories\Contracts\LocationSalesRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentLocationSalesRepository implements LocationSalesRepositoryInterface
{
    private const CHUNK = 1000;

    public function replaceFrom(Shop $shop, string $fromDate, array $rows): void
    {
        DB::transaction(function () use ($shop, $fromDate, $rows) {
            DB::table('location_daily_sales')->where('shop_id', $shop->id)->where('date', '>=', $fromDate)->delete();
            $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'was_in_stock' => true], $rows);
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('location_daily_sales')->insert($chunk);
            }
        });
    }

    public function rowsBetween(Shop $shop, array $variantIds, string $from, string $to): array
    {
        $out = [];
        foreach (array_chunk($variantIds, self::CHUNK) as $ids) {
            DB::table('location_daily_sales')->where('shop_id', $shop->id)->whereIn('variant_id', $ids)
                ->whereBetween('date', [$from, $to])->orderBy('id')
                ->each(function ($r) use (&$out) {
                    $out[(int) $r->variant_id][(int) $r->location_id][substr((string) $r->date, 0, 10)] = [
                        'sold' => (int) $r->units_sold,
                        'in_stock' => (bool) $r->was_in_stock,
                    ];
                }, 5000);
        }

        return $out;
    }

    public function pairsWithSalesSince(Shop $shop, string $fromDate): array
    {
        $out = [];
        DB::table('location_daily_sales')->where('shop_id', $shop->id)->where('date', '>=', $fromDate)
            ->where('units_sold', '>', 0)->distinct()->get(['variant_id', 'location_id'])
            ->each(function ($r) use (&$out) {
                $out[(int) $r->variant_id][(int) $r->location_id] = true;
            });

        return $out;
    }

    public function upsertStockFlags(Shop $shop, array $rows): void
    {
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id], $rows);
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('location_daily_sales')->upsert($chunk, ['variant_id', 'location_id', 'date'], ['was_in_stock']);
        }
    }

    public function deleteForShop(Shop $shop): void
    {
        DB::table('location_daily_sales')->where('shop_id', $shop->id)->delete();
    }
}
