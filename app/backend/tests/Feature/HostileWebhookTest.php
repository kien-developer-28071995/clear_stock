<?php

use App\Models\Location;
use App\Models\Shop;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// Shopify signs what it sends, but not always what we expect: retries, old API versions, fields
// left out, ids of things we never saw. A signed webhook is always acknowledged, and handling it
// (jobs run at once here) never throws, whatever is in it.

beforeEach(function () {
    Mail::fake();
    Http::fake(['*' => Http::response(['data' => []])]);
    $this->travelTo('2026-09-20 10:00:00');
    config(['queue.default' => 'sync']);
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'growth', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->variant = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
});

it('acknowledges any signed webhook and survives handling it', function (string $topic, array $payload) {
    foreach (['demo.myshopify.com', 'never-installed.myshopify.com'] as $shop) {
        $response = postWebhook($topic, $payload, $shop);
        expect($response->getStatusCode())->toBeLessThan(500, "{$topic} from {$shop}: ".substr((string) $response->getContent(), 0, 200));
    }
})->with(function () {
    $topics = [
        'app/uninstalled', 'app/scopes_update', 'shop/redact', 'customers/redact', 'customers/data_request',
        'app_subscriptions/update', 'inventory_levels/update', 'bulk_operations/finish', 'shop/update', 'products/update', 'some/unknown_topic',
    ];
    $payloads = [
        'empty' => [],
        'wrong types' => ['id' => 'x', 'shop_id' => [], 'shop_domain' => 12, 'inventory_item_id' => 'abc', 'location_id' => null, 'available' => 'lots', 'updated_at' => 'yesterday',
            'app_subscription' => 'none', 'admin_graphql_api_id' => 42, 'status' => [], 'error_code' => 7, 'current' => 'x', 'previous' => 1, 'customer' => 'someone', 'orders_to_redact' => 'all'],
        'unknown ids' => ['id' => 999999999, 'shop_id' => 999999999, 'inventory_item_id' => 999999999, 'location_id' => 999999999, 'available' => -50, 'updated_at' => '2026-09-20T10:00:00Z',
            'app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/999', 'name' => 'Platinum', 'status' => 'ACTIVE'],
            'admin_graphql_api_id' => 'gid://shopify/BulkOperation/999', 'status' => 'completed', 'customer' => ['id' => 1, 'email' => 'a@b.c'], 'orders_to_redact' => [1, 2]],
        'extremes' => ['id' => PHP_INT_MAX, 'available' => PHP_INT_MAX, 'inventory_item_id' => PHP_INT_MAX, 'location_id' => -1, 'updated_at' => '9999-12-31T23:59:59Z',
            'app_subscription' => ['admin_graphql_api_id' => str_repeat('x', 5000), 'name' => str_repeat('y', 5000), 'status' => 'WHATEVER'], 'status' => str_repeat('z', 3000)],
    ];
    foreach ($topics as $topic) {
        foreach ($payloads as $label => $payload) {
            yield "{$topic} / {$label}" => [$topic, $payload];
        }
    }
});

it('keeps the shop and its forecasts after a storm of nonsense about other things', function () {
    foreach (['inventory_levels/update', 'app_subscriptions/update', 'bulk_operations/finish', 'app/scopes_update'] as $topic) {
        postWebhook($topic, ['id' => 'x', 'available' => 'lots', 'inventory_item_id' => 'abc']);
    }

    expect($this->shop->fresh()->isInstalled())->toBeTrue()
        ->and($this->variant->forecast()->first())->not->toBeNull();
});
