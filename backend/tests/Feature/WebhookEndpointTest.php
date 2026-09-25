<?php

use App\Jobs\Webhooks\HandleAppUninstalled;
use App\Jobs\Webhooks\HandleCustomersDataRequest;
use App\Jobs\Webhooks\HandleCustomersRedact;
use App\Jobs\Webhooks\HandleScopesUpdate;
use App\Jobs\Webhooks\HandleShopRedact;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('accepts a signed webhook and queues the matching job on the webhooks queue', function (string $topic, string $job) {
    postWebhook($topic, ['shop_domain' => 'demo.myshopify.com'])->assertOk();

    Queue::assertPushedOn('webhooks', $job, fn ($j) => $j->shopDomain === 'demo.myshopify.com');
})->with([
    ['app/uninstalled', HandleAppUninstalled::class],
    ['app/scopes_update', HandleScopesUpdate::class],
    ['customers/data_request', HandleCustomersDataRequest::class],
    ['customers/redact', HandleCustomersRedact::class],
    ['shop/redact', HandleShopRedact::class],
]);

it('rejects an invalid HMAC with 401', function (string $topic) {
    postWebhook($topic, ['shop_domain' => 'demo.myshopify.com'], hmac: base64_encode('forged'))->assertUnauthorized();

    Queue::assertNothingPushed();
})->with(['app/uninstalled', 'customers/data_request', 'customers/redact', 'shop/redact']);

it('rejects a missing HMAC header with 401', function () {
    $this->postJson('/webhooks', ['x' => 1], ['X-Shopify-Topic' => 'shop/redact', 'X-Shopify-Shop-Domain' => 'demo.myshopify.com'])
        ->assertUnauthorized();
});

it('rejects a body that was tampered with after signing', function () {
    $hmac = base64_encode(hash_hmac('sha256', json_encode(['a' => 1]), TEST_API_SECRET, true));

    postWebhook('shop/redact', ['a' => 2], hmac: $hmac)->assertUnauthorized();
});

it('processes a delivery only once when Shopify retries it', function () {
    postWebhook('app/uninstalled', [], webhookId: 'same-id')->assertOk();
    postWebhook('app/uninstalled', [], webhookId: 'same-id')->assertOk();

    Queue::assertPushed(HandleAppUninstalled::class, 1);
});

it('acknowledges unknown topics and invalid shop domains without queueing', function (string $topic, string $shop) {
    postWebhook($topic, [], shop: $shop)->assertOk();

    Queue::assertNothingPushed();
})->with([
    ['products/update', 'demo.myshopify.com'],
    ['app/uninstalled', 'evil.com'],
]);

it('keeps only ids from customer compliance payloads (no contact data)', function () {
    postWebhook('customers/data_request', [
        'shop_id' => 1,
        'shop_domain' => 'demo.myshopify.com',
        'orders_requested' => [299938, 280263],
        'customer' => ['id' => 191167, 'email' => 'john@example.com', 'phone' => '555-625-1199'],
        'data_request' => ['id' => 9999],
    ])->assertOk();

    Queue::assertPushed(HandleCustomersDataRequest::class, function ($job) {
        expect($job->ids)->toBe(['customer_id' => 191167, 'orders' => [299938, 280263], 'data_request_id' => 9999])
            ->and(serialize($job))->not->toContain('john@example.com');

        return true;
    });
});
