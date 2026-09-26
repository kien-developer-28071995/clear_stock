<?php

namespace App\Services\Transfer;

use App\Enums\Feature;
use App\Exceptions\ApiException;
use App\Exceptions\PlanRequiredException;
use App\Exceptions\ShopifyApiException;
use App\Models\InventoryTransfer;
use App\Models\Location;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\TransferRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Shopify\AdminApiClient;
use App\Services\Sync\LocationSupport;
use App\Support\Entitlements;
use App\Support\Gid;
use App\Support\Monitor;
use Illuminate\Support\Facades\Log;

/**
 * Growth: move stock between locations before ordering more. Suggestions come from the
 * per-location forecasts (TransferPlanner); the merchant can create each route as a draft
 * inventory transfer in Shopify (optional scope `write_inventory_transfers`, asked for the
 * first time it is used). Recent drafts count as moved so nothing is suggested twice.
 */
class TransferService
{
    public const SCOPE = 'write_inventory_transfers';

    /** Draft transfers younger than this count as moved (drafts don't change inventory in Shopify). */
    public const DRAFT_DAYS = 7;

    private const CREATE = <<<'GQL'
        mutation TransferCreate($input: InventoryTransferCreateInput!, $idempotencyKey: String!) {
          inventoryTransferCreate(input: $input) @idempotent(key: $idempotencyKey) {
            inventoryTransfer { id name status }
            userErrors { field message }
          }
        }
        GQL;

    public function __construct(
        private readonly TransferRepositoryInterface $transfers,
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly VariantRepositoryInterface $variants,
        private readonly ShopRepositoryInterface $shops,
        private readonly LocationSupport $locationSupport,
        private readonly TransferPlanner $planner,
        private readonly AdminApiClient $admin,
    ) {}

    public function suggestions(Shop $shop): array
    {
        $this->authorize($shop);
        $recent = $this->transfers->createdSince($shop, now()->subDays(self::DRAFT_DAYS));
        $base = [
            'scope_granted' => $this->hasScope($shop),
            'recent' => $recent->map(fn (InventoryTransfer $t) => [
                'id' => $t->id,
                'shopify_transfer_id' => $t->shopify_transfer_id,
                'name' => $t->name,
                'origin' => $t->origin?->name,
                'destination' => $t->destination?->name,
                'total_units' => $t->total_units,
                'created_at' => $t->created_at->toIso8601String(),
            ])->values()->all(),
        ];

        // Per-location forecasts need Growth + 2 active locations + the fulfillment order scopes.
        if (! $this->locationSupport->enabled($shop)) {
            return ['available' => false, 'routes' => []] + $base;
        }

        $drafted = [];
        foreach ($recent as $t) {
            foreach ($t->items as $item) {
                $drafted[$item['variant_id']][] = ['origin' => $t->origin_location_id, 'destination' => $t->destination_location_id, 'quantity' => (int) $item['quantity']];
            }
        }

        $names = collect($this->forecasts->activeLocations($shop))->pluck('name', 'id');
        $routes = [];
        foreach ($this->transfers->locationForecasts($shop) as $variantId => $rows) {
            $byLocation = $rows->keyBy('location_id');
            $moves = $this->planner->plan($rows->map(fn ($r) => [
                'location_id' => (int) $r->location_id,
                'current_stock' => (int) $r->current_stock,
                'incoming_stock' => (int) $r->incoming_stock,
                'avg_daily_sales' => (float) $r->avg_daily_sales,
                'reorder_point' => (int) $r->reorder_point,
                'target_stock' => (int) $r->target_stock,
                'stockout_date' => $r->stockout_date !== null ? substr((string) $r->stockout_date, 0, 10) : null,
                'days_of_cover' => $r->days_of_cover !== null ? (float) $r->days_of_cover : null,
            ])->values()->all(), $drafted[$variantId] ?? []);

            foreach ($moves as $move) {
                $to = $byLocation[$move['destination']];
                $from = $byLocation[$move['origin']];
                $key = "{$move['origin']}-{$move['destination']}";
                $routes[$key] ??= [
                    'origin' => ['id' => $move['origin'], 'name' => $names[$move['origin']] ?? null],
                    'destination' => ['id' => $move['destination'], 'name' => $names[$move['destination']] ?? null],
                    'items' => [],
                ];
                $routes[$key]['items'][] = [
                    'variant_id' => (int) $variantId,
                    'name' => $to->title !== null && $to->title !== 'Default Title' ? "{$to->product_title} - {$to->title}" : $to->product_title,
                    'sku' => $to->sku,
                    'quantity' => $move['quantity'],
                    'origin_stock' => (int) $from->current_stock,
                    'destination_stock' => (int) $to->current_stock,
                    'destination_days_of_cover' => $to->days_of_cover !== null ? (float) $to->days_of_cover : null,
                    'destination_stockout_date' => $to->stockout_date !== null ? substr((string) $to->stockout_date, 0, 10) : null,
                ];
            }
        }

        // Most urgent route first (earliest stock-out among its products), products likewise.
        $urgency = fn (array $item) => $item['destination_stockout_date'] ?? '9999-12-31';
        $routes = collect($routes)->map(function (array $route) use ($urgency) {
            usort($route['items'], fn ($a, $b) => $urgency($a) <=> $urgency($b));

            return $route + ['total_units' => array_sum(array_column($route['items'], 'quantity'))];
        })->sortBy(fn (array $route) => $urgency($route['items'][0]))->values()->all();

        return ['available' => true, 'routes' => $routes] + $base;
    }

    /**
     * Creates a draft transfer in Shopify. Quantities may differ from the suggestion (the
     * merchant can edit them); Shopify checks them against the origin's stock when shipping.
     *
     * @param  array<int, array{variant_id: int, quantity: int}>  $items
     */
    public function create(Shop $shop, int $originId, int $destinationId, array $items, string $idempotencyKey): InventoryTransfer
    {
        $this->authorize($shop);

        $locations = Location::query()->forShop($shop)->where('is_active', true)->whereIn('id', [$originId, $destinationId])->get()->keyBy('id');
        if ($originId === $destinationId || ! $locations->has($originId) || ! $locations->has($destinationId)) {
            throw new ApiException('invalid_location', 422);
        }

        $variants = $this->variants->findMany($shop, array_column($items, 'variant_id'))->keyBy('id');
        $lineItems = [];
        foreach ($items as $item) {
            $variant = $variants->get($item['variant_id']);
            if ($variant === null || $variant->inventory_item_id === null) {
                throw new ApiException('invalid_product', 422);
            }
            $lineItems[] = ['inventoryItemId' => "gid://shopify/InventoryItem/{$variant->inventory_item_id}", 'quantity' => (int) $item['quantity']];
        }

        $this->ensureScope($shop);

        try {
            $data = $this->admin->query($shop, self::CREATE, [
                'input' => [
                    'originLocationId' => "gid://shopify/Location/{$locations[$originId]->shopify_location_id}",
                    'destinationLocationId' => "gid://shopify/Location/{$locations[$destinationId]->shopify_location_id}",
                    'lineItems' => $lineItems,
                    'tags' => ['clear-stock'],
                    'note' => 'Suggested by Clear Stock to cover this location before ordering more.',
                ],
                'idempotencyKey' => $idempotencyKey,
            ]);
        } catch (ShopifyApiException $e) {
            if (collect($e->errors)->contains(fn ($error) => ($error['extensions']['code'] ?? null) === 'ACCESS_DENIED')) {
                throw new ApiException('scope_required', 403, ['scope' => self::SCOPE]);
            }
            Monitor::caught($e, 'creating a draft transfer', ['shop' => $shop->domain]);
            throw new ApiException('shopify_unavailable', 502);
        }

        $result = $data['inventoryTransferCreate'] ?? [];
        if (($result['userErrors'] ?? []) !== [] || empty($result['inventoryTransfer']['id'])) {
            // Shopify's messages are English text: logged, the app shows a translated code.
            Log::warning('Shopify rejected a draft transfer', ['shop' => $shop->domain, 'errors' => $result['userErrors'] ?? []]);
            throw new ApiException('transfer_rejected', 422);
        }

        return $this->transfers->create($shop, [
            'shopify_transfer_id' => Gid::id($result['inventoryTransfer']['id']),
            'name' => (string) ($result['inventoryTransfer']['name'] ?? ''),
            'origin_location_id' => $originId,
            'destination_location_id' => $destinationId,
            'items' => array_map(fn ($i) => ['variant_id' => (int) $i['variant_id'], 'quantity' => (int) $i['quantity']], $items),
            'total_units' => array_sum(array_column($items, 'quantity')),
        ]);
    }

    private function authorize(Shop $shop): void
    {
        if (! Entitlements::for($shop)->has(Feature::Locations)) {
            throw new PlanRequiredException(Feature::Locations);
        }
    }

    private function hasScope(Shop $shop): bool
    {
        return in_array(self::SCOPE, explode(',', (string) $shop->scopes), true);
    }

    /**
     * The merchant grants the optional scope from the app (App Bridge); the app/scopes_update
     * webhook may not have arrived yet, so ask Shopify before refusing.
     */
    private function ensureScope(Shop $shop): void
    {
        if ($this->hasScope($shop)) {
            return;
        }

        try {
            $data = $this->admin->query($shop, '{ currentAppInstallation { accessScopes { handle } } }');
        } catch (ShopifyApiException $e) {
            Monitor::caught($e, 'checking granted scopes', ['shop' => $shop->domain]);
            throw new ApiException('shopify_unavailable', 502);
        }

        $granted = array_column($data['currentAppInstallation']['accessScopes'] ?? [], 'handle');
        $this->shops->update($shop, ['scopes' => implode(',', $granted)]);
        if (! in_array(self::SCOPE, $granted, true)) {
            throw new ApiException('scope_required', 403, ['scope' => self::SCOPE]);
        }
    }
}
