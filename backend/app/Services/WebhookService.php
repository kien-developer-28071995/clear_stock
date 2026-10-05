<?php

namespace App\Services;

use App\Enums\WebhookTopic;
use App\Jobs\Webhooks\HandleAppSubscriptionUpdate;
use App\Jobs\Webhooks\HandleAppUninstalled;
use App\Jobs\Webhooks\HandleBulkOperationFinished;
use App\Jobs\Webhooks\HandleCustomersDataRequest;
use App\Jobs\Webhooks\HandleCustomersRedact;
use App\Jobs\Webhooks\HandleInventoryLevelUpdate;
use App\Jobs\Webhooks\HandleScopesUpdate;
use App\Jobs\Webhooks\HandleShopRedact;
use App\Support\CacheKeys;
use App\Support\ShopDomain;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(private readonly Cache $cache) {}

    /** Enqueue a verified webhook. Returns false when it was ignored (duplicate/unknown). */
    public function receive(string $topic, string $shopDomain, ?string $webhookId, array $payload): bool
    {
        $shop = ShopDomain::normalize($shopDomain);
        $topicEnum = WebhookTopic::tryFrom($topic);

        if ($shop === null || $topicEnum === null) {
            Log::warning('Ignoring webhook', ['topic' => $topic, 'shop' => $shopDomain]);

            return false;
        }

        // Shopify can deliver the same webhook more than once.
        if ($webhookId !== null && ! $this->cache->add(CacheKeys::webhookDelivery($webhookId), 1, CacheKeys::TTL_WEBHOOK_DEDUPE)) {
            Log::info('Duplicate webhook skipped', ['topic' => $topic, 'shop' => $shop, 'webhook_id' => $webhookId]);

            return false;
        }

        $job = match ($topicEnum) {
            WebhookTopic::AppUninstalled => new HandleAppUninstalled($shop),
            WebhookTopic::AppScopesUpdate => new HandleScopesUpdate($shop, array_values(array_filter(is_array($payload['current'] ?? null) ? $payload['current'] : [], 'is_string'))),
            // Only ids are kept: the payload carries customer contact data we must not store.
            WebhookTopic::CustomersDataRequest => new HandleCustomersDataRequest($shop, $this->requestIds($payload)),
            WebhookTopic::CustomersRedact => new HandleCustomersRedact($shop, $this->requestIds($payload)),
            WebhookTopic::ShopRedact => new HandleShopRedact($shop),
            WebhookTopic::AppSubscriptionsUpdate => new HandleAppSubscriptionUpdate($shop),
            WebhookTopic::BulkOperationsFinish => new HandleBulkOperationFinished($shop, is_scalar($payload['admin_graphql_api_id'] ?? null) ? (string) $payload['admin_graphql_api_id'] : ''),
            WebhookTopic::InventoryLevelsUpdate => new HandleInventoryLevelUpdate($shop, [
                'inventory_item_id' => $this->int($payload['inventory_item_id'] ?? null),
                'location_id' => $this->int($payload['location_id'] ?? null),
                'available' => $this->int($payload['available'] ?? null),
                'updated_at' => is_string($payload['updated_at'] ?? null) ? $payload['updated_at'] : null,
            ]),
        };

        try {
            dispatch($job->onQueue('webhooks'));
        } catch (\Throwable $e) {
            // Let Shopify retry the delivery instead of silently dropping it.
            if ($webhookId !== null) {
                $this->cache->forget(CacheKeys::webhookDelivery($webhookId));
            }
            throw $e;
        }

        Log::info('Webhook queued', ['topic' => $topic, 'shop' => $shop, 'webhook_id' => $webhookId]);

        return true;
    }

    /** @return array{customer_id: ?int, orders: array<int>, data_request_id: ?int} */
    private function requestIds(array $payload): array
    {
        return [
            'customer_id' => $this->int(is_array($payload['customer'] ?? null) ? ($payload['customer']['id'] ?? null) : null),
            'orders' => array_values(array_filter(array_map(fn ($id) => $this->int($id), is_array($orders = $payload['orders_requested'] ?? $payload['orders_to_redact'] ?? null) ? $orders : []), fn ($id) => $id !== null)),
            'data_request_id' => $this->int(is_array($payload['data_request'] ?? null) ? ($payload['data_request']['id'] ?? null) : null),
        ];
    }

    /** A whole number from a payload field, or null when it is missing or not a number (Shopify signs the body, not its shape). */
    private function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) ? (int) $value : null;
    }
}
