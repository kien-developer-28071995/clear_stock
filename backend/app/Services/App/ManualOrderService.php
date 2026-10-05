<?php

namespace App\Services\App;

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\ManualOrder;
use App\Models\Shop;
use App\Repositories\Contracts\ManualOrderRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Forecast\ForecastService;
use App\Support\CacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * "Mark as ordered": orders placed outside Shopify (email, phone, supplier portal) have no
 * "incoming" in Shopify, so without this the app would suggest ordering them again the next day.
 * Open orders count as on the way until received, cancelled or a few days past their date.
 */
class ManualOrderService
{
    /** The same order arriving again within this many seconds is the same order. */
    private const DUPLICATE_WINDOW_SECONDS = 10;

    /** Up to this many products the forecasts are recomputed at once, more go to the queue. */
    private const SYNC_RECOMPUTE = 50;

    public function __construct(
        private readonly ManualOrderRepositoryInterface $orders,
        private readonly VariantRepositoryInterface $variants,
        private readonly ForecastService $engine,
    ) {}

    /** @return array{today: string, open: Collection<int, ManualOrder>, closed: Collection<int, ManualOrder>} */
    public function list(Shop $shop): array
    {
        return [
            'today' => $this->today($shop),
            'open' => $this->orders->list($shop, true, 500),
            'closed' => $this->orders->list($shop, false, 50),
        ];
    }

    /**
     * @param  array<int, array{variant_id: int, quantity: int}>  $items
     * @param  ?string  $expectedOn  null = today + each product's lead time
     * @return int orders recorded
     */
    public function record(Shop $shop, array $items, ?string $expectedOn = null, ?string $reference = null, string $source = ManualOrder::SOURCE_MANUAL): int
    {
        // One at a time per shop: the duplicate check below must see the order a request sent in
        // the same moment has just recorded.
        return Cache::lock(CacheKeys::manualOrderRecordLock($shop->id), 10)
            ->block(5, fn () => $this->recordLocked($shop, $items, $expectedOn, $reference, $source));
    }

    private function recordLocked(Shop $shop, array $items, ?string $expectedOn, ?string $reference, string $source): int
    {
        $variants = $this->variants->findMany($shop, array_column($items, 'variant_id'));
        $items = array_values(array_filter($items, fn ($i) => isset($variants[$i['variant_id']]) && $i['quantity'] > 0));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'no_items']);
        }
        // The very same order again within seconds is a double click or a retried request, not a
        // second order: counting it twice would hide a real need to reorder. Only while the first
        // one is still open, so "ordered, cancelled, ordered again" records the second.
        $justRecorded = $this->orders->openRecordedSince($shop, now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS));
        $requested = count($items);
        $items = array_values(array_filter($items, fn ($i) => ! in_array("{$i['variant_id']}|".(int) $i['quantity']."|{$reference}|{$source}", $justRecorded, true)));
        if ($items === []) {
            return $requested;
        }

        $today = CarbonImmutable::parse($this->today($shop));
        $leadTimes = $expectedOn === null ? $this->orders->leadTimes($shop, array_column($items, 'variant_id')) : [];

        $this->orders->createMany($shop, array_map(fn ($i) => [
            'variant_id' => $i['variant_id'],
            'supplier_id' => $variants[$i['variant_id']]->supplier_id,
            'quantity' => (int) $i['quantity'],
            'ordered_on' => $today->toDateString(),
            'expected_on' => $expectedOn ?? $today->addDays($leadTimes[$i['variant_id']] ?? $shop->default_lead_time_days)->toDateString(),
            'reference' => $reference,
            'source' => $source,
            'status' => ManualOrder::OPEN,
        ], $items));
        $this->recompute($shop, array_column($items, 'variant_id'));

        return $requested;
    }

    public function find(Shop $shop, int $id): ?ManualOrder
    {
        return $this->orders->find($shop, $id);
    }

    /** @param array{status?: string, expected_on?: string, quantity?: int, received_quantity?: int} $data */
    public function update(Shop $shop, ManualOrder $order, array $data): ManualOrder
    {
        // A partial delivery keeps the order open for the rest; the last units receive it.
        if (isset($data['received_quantity']) && ! isset($data['status']) && $order->status === ManualOrder::OPEN) {
            $data['received_quantity'] = min((int) $data['received_quantity'], (int) ($data['quantity'] ?? $order->quantity));
            if ($data['received_quantity'] >= (int) ($data['quantity'] ?? $order->quantity)) {
                $data['status'] = ManualOrder::RECEIVED;
            }
        }
        if (isset($data['status'])) {
            $data['closed_at'] = $data['status'] === ManualOrder::OPEN ? null : now();
            if ($data['status'] === ManualOrder::RECEIVED) {
                $data['received_quantity'] = (int) ($data['quantity'] ?? $order->quantity);
            }
        }
        $order = $this->orders->update($order, $data);
        $this->recompute($shop, [$order->variant_id]);

        return $order;
    }

    public function today(Shop $shop): string
    {
        return CarbonImmutable::now($shop->timezone)->toDateString();
    }

    /** @param array<int, int> $variantIds */
    private function recompute(Shop $shop, array $variantIds): void
    {
        $variantIds = array_values(array_unique($variantIds));
        count($variantIds) <= self::SYNC_RECOMPUTE
            ? $this->engine->runForShop($shop, $variantIds)
            : RecomputeForecasts::dispatch($shop->id, $variantIds);
    }
}
