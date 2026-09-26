<?php

namespace App\Services;

use App\Enums\WebhookTopic;
use App\Jobs\Webhooks\HandleAppSubscriptionUpdate;
use App\Jobs\Webhooks\HandleAppUninstalled;
use App\Jobs\Webhooks\HandleBulkOperationFinished;
use App\Jobs\Webhooks\HandleCustomersDataRequest;
use App\Jobs\Webhooks\HandleCustomersRedact;
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
            WebhookTopic::AppScopesUpdate => new HandleScopesUpdate($shop, $payload['current'] ?? []),
            // Only ids are kept: the payload carries customer contact data we must not store.
            WebhookTopic::CustomersDataRequest => new HandleCustomersDataRequest($shop, $this->requestIds($payload)),
            WebhookTopic::CustomersRedact => new HandleCustomersRedact($shop, $this->requestIds($payload)),
            WebhookTopic::ShopRedact => new HandleShopRedact($shop),
            WebhookTopic::AppSubscriptionsUpdate => new HandleAppSubscriptionUpdate($shop),
            WebhookTopic::BulkOperationsFinish => new HandleBulkOperationFinished($shop, (string) ($payload['admin_graphql_api_id'] ?? '')),
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
            'customer_id' => isset($payload['customer']['id']) ? (int) $payload['customer']['id'] : null,
            'orders' => array_map('intval', $payload['orders_requested'] ?? $payload['orders_to_redact'] ?? []),
            'data_request_id' => isset($payload['data_request']['id']) ? (int) $payload['data_request']['id'] : null,
        ];
    }
}
