<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Bulk query operations (API 2026-01+: up to 5 concurrent per shop, polled with bulkOperation(id:)).
 * https://shopify.dev/docs/api/usage/bulk-operations/queries
 */
class BulkOperationClient
{
    private const RUN = <<<'GQL'
        mutation RunBulkQuery($query: String!) {
          bulkOperationRunQuery(query: $query) {
            bulkOperation { id status }
            userErrors { field message }
          }
        }
        GQL;

    private const STATUS = <<<'GQL'
        query BulkOperationStatus($id: ID!) {
          bulkOperation(id: $id) { id status errorCode objectCount url }
        }
        GQL;

    public function __construct(
        private readonly AdminApiClient $admin,
        private readonly Http $http,
    ) {}

    public function run(Shop $shop, string $query): BulkOperation
    {
        $result = $this->admin->query($shop, self::RUN, ['query' => $query])['bulkOperationRunQuery'] ?? [];

        if (! empty($result['userErrors'])) {
            throw new ShopifyApiException('Bulk query rejected: '.$result['userErrors'][0]['message'], errors: $result['userErrors']);
        }

        return BulkOperation::fromArray($result['bulkOperation']);
    }

    public function status(Shop $shop, string $id): BulkOperation
    {
        $data = $this->admin->query($shop, self::STATUS, ['id' => $id])['bulkOperation'] ?? null;
        if ($data === null) {
            throw new ShopifyApiException("Bulk operation {$id} not found.");
        }

        return BulkOperation::fromArray($data);
    }

    /** Stream the JSONL result to a local file (results can be hundreds of MB). */
    public function download(string $url, string $path): void
    {
        try {
            $response = $this->http->timeout(600)->sink($path)->get($url);
        } catch (ConnectionException $e) {
            throw new ShopifyApiException('Could not download bulk operation result: '.$e->getMessage(), retryable: true);
        }

        if (! $response->successful()) {
            throw new ShopifyApiException("Bulk result download failed with HTTP {$response->status()}.", retryable: $response->serverError());
        }
    }
}
