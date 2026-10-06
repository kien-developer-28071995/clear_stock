<?php

namespace App\Repositories\Eloquent;

use App\Models\ManualOrder;
use App\Models\Shop;
use App\Repositories\Contracts\ManualOrderRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentManualOrderRepository implements ManualOrderRepositoryInterface
{
    public function onTheWay(Shop $shop, string $countedFrom, ?array $variantIds = null): array
    {
        return DB::table('manual_orders')->where('shop_id', $shop->id)->where('status', ManualOrder::OPEN)
            ->where('expected_on', '>=', $countedFrom)
            ->when($variantIds !== null, fn ($q) => $q->whereIn('variant_id', $variantIds))
            ->groupBy('variant_id')
            ->whereColumn('received_quantity', '<', 'quantity')
            // Partial deliveries: only what has not arrived yet is still on the way.
            ->selectRaw('variant_id, SUM(quantity - received_quantity) as units, MIN(expected_on) as expected_on, COUNT(*) as n')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->variant_id => [
                'units' => (int) $r->units, 'expected_on' => substr((string) $r->expected_on, 0, 10), 'orders' => (int) $r->n,
            ]])->all();
    }

    public function list(Shop $shop, bool $open, int $limit): Collection
    {
        return ManualOrder::query()->forShop($shop)->with(['variant', 'supplier:id,name'])
            ->when($open, fn ($q) => $q->where('status', ManualOrder::OPEN)->orderBy('expected_on'),
                fn ($q) => $q->where('status', '!=', ManualOrder::OPEN)->orderByDesc('closed_at'))
            ->orderBy('id')->limit($limit)->get();
    }

    public function find(Shop $shop, int $id): ?ManualOrder
    {
        return ManualOrder::query()->forShop($shop)->find($id);
    }

    public function createMany(Shop $shop, array $rows): void
    {
        $now = now();
        DB::table('manual_orders')->insert(array_map(fn ($r) => $r + ['shop_id' => $shop->id, 'created_at' => $now, 'updated_at' => $now], $rows));
    }

    public function update(ManualOrder $order, array $attributes): ManualOrder
    {
        $order->fill($attributes)->save();

        return $order;
    }

    public function leadTimes(Shop $shop, array $variantIds): array
    {
        return DB::table('forecasts')->where('shop_id', $shop->id)->whereNull('location_id')->whereIn('variant_id', $variantIds)
            ->pluck('explanation', 'variant_id')
            ->map(fn ($e) => (int) (json_decode((string) $e, true)['lead_time']['days'] ?? 0))
            ->mapWithKeys(fn ($days, $id) => [(int) $id => $days])->all();
    }

    public function deliveryDays(Shop $shop, string $fromDate): array
    {
        $out = [];
        DB::table('manual_orders')->where('shop_id', $shop->id)->where('status', ManualOrder::RECEIVED)
            ->whereNotNull('supplier_id')->whereNotNull('closed_at')->where('ordered_on', '>=', $fromDate)
            ->orderBy('id')->get(['supplier_id', 'ordered_on', 'closed_at'])
            ->each(function ($r) use (&$out, $shop) {
                $received = Carbon::parse($r->closed_at, 'UTC')->setTimezone($shop->timezone)->startOfDay();
                $days = (int) Carbon::parse(substr((string) $r->ordered_on, 0, 10), $shop->timezone)->diffInDays($received, false);
                if ($days >= 0) {
                    $out[(int) $r->supplier_id][] = $days;
                }
            });

        return $out;
    }

    public function spentSince(Shop $shop, string $fromDate): array
    {
        $r = DB::table('manual_orders')->join('variants', 'variants.id', '=', 'manual_orders.variant_id')
            ->where('manual_orders.shop_id', $shop->id)->where('manual_orders.status', '!=', ManualOrder::CANCELLED)
            ->where('manual_orders.ordered_on', '>=', $fromDate)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(manual_orders.quantity * variants.unit_cost), 0) as cost, '
                .'SUM(CASE WHEN variants.unit_cost IS NULL THEN 1 ELSE 0 END) as missing')
            ->first();

        return ['orders' => (int) $r->n, 'cost' => round((float) $r->cost, 2), 'missing_cost' => (int) $r->missing];
    }
}
