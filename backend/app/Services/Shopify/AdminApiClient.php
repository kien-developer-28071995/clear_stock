<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Minimal Admin GraphQL API client (https://shopify.dev/docs/api/admin-graphql).
 */
class AdminApiClient
{
    public function __construct(
        private readonly Http $http,
        private readonly ShopTokenService $tokens,
        private readonly string $apiVersion,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Run a query/mutation and return its `data` payload.
     *
     * @throws ShopifyApiException
     */
    public function query(Shop $shop, string $query, array $variables = []): array
    {
        $token = $this->tokens->accessToken($shop);

        try {
            $response = $this->http
                ->withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post(
                    "https://{$shop->domain}/admin/api/{$this->apiVersion}/graphql.json",
                    array_filter(['query' => $query, 'variables' => $variables ?: null]),
                );
        } catch (ConnectionException $e) {
            throw new ShopifyApiException("Could not reach {$shop->domain}: {$e->getMessage()}", retryable: true);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new ShopifyApiException("Admin API HTTP {$response->status()}", retryable: true);
        }
        if (! $response->successful()) {
            throw new ShopifyApiException("Admin API HTTP {$response->status()}");
        }

        $errors = $response->json('errors') ?? [];
        if ($errors !== []) {
            $throttled = collect($errors)->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED');
            throw new ShopifyApiException('Admin API error: '.($errors[0]['message'] ?? 'unknown'), retryable: $throttled, errors: $errors);
        }

        return $response->json('data') ?? [];
    }
}
