<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentDailySalesRepository implements DailySalesRepositoryInterface
{
    private const CHUNK = 1000;

    public function resetSalesFrom(Shop $shop, string $fromDate): void
    {
        DB::table('daily_sales')->where('shop_id', $shop->id)->where('date', '>=', $fromDate)
            ->update(['units_sold' => 0, 'units_returned' => 0]);
    }

    public function upsertSales(Shop $shop, array $rows): void
    {
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'was_in_stock' => true], $rows);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('daily_sales')->upsert($chunk, ['variant_id', 'date'], ['units_sold', 'units_returned']);
        }
    }

    public function topSellers(Shop $shop, array $candidateIds, string $fromDate, int $limit): array
    {
        $sold = DB::table('daily_sales')->where('shop_id', $shop->id)->where('date', '>=', $fromDate)
            ->groupBy('variant_id')->selectRaw('variant_id, SUM(units_sold) as units')
            ->pluck('units', 'variant_id')->all();

        $ranked = $candidateIds;
        usort($ranked, fn ($a, $b) => [(int) ($sold[$b] ?? 0), $a] <=> [(int) ($sold[$a] ?? 0), $b]);

        return array_slice($ranked, 0, $limit);
    }

    public function netUnitsBetween(Shop $shop, string $from, string $to): int
    {
        return (int) DB::table('daily_sales')->where('shop_id', $shop->id)->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(units_sold - units_returned), 0) as units')->value('units');
    }

    public function firstSalesDate(Shop $shop): ?string
    {
        $date = DB::table('daily_sales')->where('shop_id', $shop->id)->where('units_sold', '>', 0)->min('date');

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    public function variantIdsWithSalesSince(Shop $shop, string $fromDate): array
    {
        return DB::table('daily_sales')->where('shop_id', $shop->id)->where('date', '>=', $fromDate)
            ->where('units_sold', '>', 0)->distinct()->pluck('variant_id')->map(fn ($id) => (int) $id)->all();
    }

    public function rowsBetween(Shop $shop, array $variantIds, string $from, string $to): array
    {
        $out = [];
        foreach (array_chunk($variantIds, self::CHUNK) as $ids) {
            DB::table('daily_sales')->where('shop_id', $shop->id)->whereIn('variant_id', $ids)
                ->whereBetween('date', [$from, $to])
                ->orderBy('id')
                ->each(function ($r) use (&$out) {
                    $out[(int) $r->variant_id][substr((string) $r->date, 0, 10)] = [
                        'sold' => (int) $r->units_sold,
                        'returned' => (int) $r->units_returned,
                        'stock' => $r->end_of_day_stock === null ? null : (int) $r->end_of_day_stock,
                        'in_stock' => (bool) $r->was_in_stock,
                    ];
                }, 5000);
        }

        return $out;
    }

    public function upsertStockFlags(Shop $shop, array $rows): void
    {
        $rows = array_map(fn ($r) => $r + ['shop_id' => $shop->id], $rows);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('daily_sales')->upsert($chunk, ['variant_id', 'date'], ['end_of_day_stock', 'was_in_stock']);
        }
    }
}
