<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Support\Monitor;

/**
 * Shopify Billing API (app subscriptions).
 * https://shopify.dev/docs/api/admin-graphql/latest/mutations/appSubscriptionCreate
 */
class BillingClient
{
    private const CREATE = <<<'GQL'
        mutation CreateSubscription($name: String!, $returnUrl: URL!, $test: Boolean, $trialDays: Int, $lineItems: [AppSubscriptionLineItemInput!]!) {
          appSubscriptionCreate(name: $name, returnUrl: $returnUrl, test: $test, trialDays: $trialDays,
                                lineItems: $lineItems, replacementBehavior: STANDARD) {
            appSubscription { id status }
            confirmationUrl
            userErrors { field message }
          }
        }
        GQL;

    private const CANCEL = <<<'GQL'
        mutation CancelSubscription($id: ID!) {
          appSubscriptionCancel(id: $id) {
            appSubscription { id status }
            userErrors { field message }
          }
        }
        GQL;

    private const ACTIVE = <<<'GQL'
        query ActiveSubscriptions {
          currentAppInstallation {
            activeSubscriptions { id name status test currentPeriodEnd }
          }
        }
        GQL;

    private const PLAN = <<<'GQL'
        query ShopPlan { shop { plan { partnerDevelopment } } }
        GQL;

    public function __construct(private readonly AdminApiClient $admin) {}

    /**
     * Partner development store (e.g. the App Store reviewer's store): charges there must be
     * test charges. Unknown (API error) = false, so a real store is never given a free test charge.
     * https://shopify.dev/docs/api/admin-graphql/latest/objects/ShopPlan
     */
    public function isDevelopmentStore(Shop $shop): bool
    {
        try {
            return (bool) ($this->admin->query($shop, self::PLAN)['shop']['plan']['partnerDevelopment'] ?? false);
        } catch (ShopifyApiException $e) {
            Monitor::expected($e, 'billing: development store check', ['shop' => $shop->domain]);

            return false;
        }
    }

    /** @return array{id: string, confirmation_url: string} */
    public function create(Shop $shop, string $name, float $price, string $currency, string $interval, string $returnUrl, bool $test, int $trialDays): array
    {
        $result = $this->admin->query($shop, self::CREATE, [
            'name' => $name,
            'returnUrl' => $returnUrl,
            'test' => $test,
            'trialDays' => $trialDays > 0 ? $trialDays : null,
            'lineItems' => [[
                'plan' => ['appRecurringPricingDetails' => [
                    'price' => ['amount' => $price, 'currencyCode' => $currency],
                    'interval' => $interval,
                ]],
            ]],
        ])['appSubscriptionCreate'] ?? [];

        if (! empty($result['userErrors']) || empty($result['confirmationUrl'])) {
            throw new ShopifyApiException('Could not create the subscription: '.($result['userErrors'][0]['message'] ?? 'unknown error'), errors: $result['userErrors'] ?? []);
        }

        return ['id' => $result['appSubscription']['id'], 'confirmation_url' => $result['confirmationUrl']];
    }

    public function cancel(Shop $shop, string $subscriptionId): void
    {
        $result = $this->admin->query($shop, self::CANCEL, ['id' => $subscriptionId])['appSubscriptionCancel'] ?? [];

        if (! empty($result['userErrors'])) {
            throw new ShopifyApiException('Could not cancel the subscription: '.$result['userErrors'][0]['message'], errors: $result['userErrors']);
        }
    }

    /** @return array<int, array{id: string, name: string, status: string, test: bool, currentPeriodEnd: ?string}> */
    public function active(Shop $shop): array
    {
        return $this->admin->query($shop, self::ACTIVE)['currentAppInstallation']['activeSubscriptions'] ?? [];
    }
}
