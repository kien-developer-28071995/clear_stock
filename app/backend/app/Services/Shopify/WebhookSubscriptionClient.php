<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;

/**
 * Shop-specific webhook subscriptions, for topics only some shops need (app-wide topics
 * live in shopify.app.toml). The list only returns shop-specific subscriptions.
 * https://shopify.dev/docs/api/admin-graphql/latest/mutations/webhookSubscriptionCreate
 */
class WebhookSubscriptionClient
{
    private const LIST = <<<'GQL'
        query WebhookSubscriptions($topics: [WebhookSubscriptionTopic!]) {
          webhookSubscriptions(first: 25, topics: $topics) {
            nodes { id topic uri }
          }
        }
        GQL;

    private const CREATE = <<<'GQL'
        mutation WebhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id }
            userErrors { field message }
          }
        }
        GQL;

    private const DELETE = <<<'GQL'
        mutation WebhookSubscriptionDelete($id: ID!) {
          webhookSubscriptionDelete(id: $id) {
            deletedWebhookSubscriptionId
            userErrors { field message }
          }
        }
        GQL;

    public function __construct(private readonly AdminApiClient $admin) {}

    /** @return array<int, array{id: string, uri: string}> */
    public function list(Shop $shop, string $topic): array
    {
        $nodes = $this->admin->query($shop, self::LIST, ['topics' => [$topic]])['webhookSubscriptions']['nodes'] ?? [];

        return array_map(fn (array $n) => ['id' => (string) $n['id'], 'uri' => (string) ($n['uri'] ?? '')], $nodes);
    }

    /** @return string the subscription gid */
    public function create(Shop $shop, string $topic, string $uri): string
    {
        $result = $this->admin->query($shop, self::CREATE, [
            'topic' => $topic,
            'webhookSubscription' => ['uri' => $uri],
        ])['webhookSubscriptionCreate'] ?? [];

        if (! empty($result['userErrors']) || empty($result['webhookSubscription']['id'])) {
            throw new ShopifyApiException('Could not subscribe to '.$topic.': '.($result['userErrors'][0]['message'] ?? 'unknown error'), errors: $result['userErrors'] ?? []);
        }

        return (string) $result['webhookSubscription']['id'];
    }

    public function delete(Shop $shop, string $id): void
    {
        $result = $this->admin->query($shop, self::DELETE, ['id' => $id])['webhookSubscriptionDelete'] ?? [];

        if (! empty($result['userErrors'])) {
            throw new ShopifyApiException('Could not delete webhook subscription: '.$result['userErrors'][0]['message'], errors: $result['userErrors']);
        }
    }
}
