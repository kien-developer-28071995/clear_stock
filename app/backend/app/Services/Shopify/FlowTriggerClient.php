<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;

/**
 * Starts the merchant's Shopify Flow workflows that use one of our trigger extensions
 * (extensions/flow-*). Payload under 50 KB, keys = the extension's field keys.
 * https://shopify.dev/docs/api/admin-graphql/latest/mutations/flowTriggerReceive
 */
class FlowTriggerClient
{
    private const RECEIVE = <<<'GQL'
        mutation FlowTriggerReceive($handle: String, $payload: JSON) {
          flowTriggerReceive(handle: $handle, payload: $payload) {
            userErrors { field message }
          }
        }
        GQL;

    public function __construct(private readonly AdminApiClient $admin) {}

    /** @throws ShopifyApiException on API errors and rejected payloads */
    public function trigger(Shop $shop, string $handle, array $payload): void
    {
        $errors = $this->admin->query($shop, self::RECEIVE, ['handle' => $handle, 'payload' => $payload])['flowTriggerReceive']['userErrors'] ?? [];

        if ($errors !== []) {
            throw new ShopifyApiException("Flow trigger {$handle} rejected: ".($errors[0]['message'] ?? 'unknown error'), errors: $errors);
        }
    }
}
