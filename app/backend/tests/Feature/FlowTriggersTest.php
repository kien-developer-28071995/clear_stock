<?php

use App\Exceptions\ShopifyApiException;
use App\Jobs\SendFlowTriggers;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\FlowRepositoryInterface;
use App\Services\Flow\FlowTriggerService;
use App\Services\Forecast\ForecastService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'growth',
        'default_lead_time_days' => 14, 'default_safety_days' => 7]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->sent = new ArrayObject;
    $this->failAfter = null;   // Shopify answers 503 once this many triggers went out
    Http::fake(['*/graphql.json' => function (Request $r) {
        if ($this->failAfter !== null && count($this->sent) >= $this->failAfter) {
            return Http::response([], 503);
        }
        $this->sent->append($r->data()['variables']);

        return Http::response(['data' => ['flowTriggerReceive' => ['userErrors' => []]]]);
    }]);
});

function lifecycle(array $body, ?string $hmac = null)
{
    $json = json_encode($body);

    return test()->call('POST', '/flow/lifecycle', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac ?? base64_encode(hash_hmac('sha256', $json, TEST_API_SECRET, true)),
    ], content: $json);
}

function enableFlow(Shop $shop): void
{
    app(FlowRepositoryInterface::class)->recordLifecycle($shop, 'Product reorder date reached', true, now());
}

function forecastNow(Shop $shop): void
{
    Queue::fake();
    app(ForecastService::class)->runForShop($shop);
}

describe('lifecycle callback', function () {
    it('records whether the shop has an active workflow, newest callback wins', function () {
        $body = fn (bool $on, string $at) => ['flow_trigger_definition_id' => 'Product reorder date reached', 'has_enabled_flow' => $on,
            'shop_id' => '1', 'shopify_domain' => 'demo.myshopify.com', 'timestamp' => $at];
        $flow = app(FlowRepositoryInterface::class);

        lifecycle($body(true, '2026-09-20T09:00:00.000Z'))->assertOk();
        expect($flow->hasEnabledFlow($this->shop))->toBeTrue();

        lifecycle($body(false, '2026-09-20T08:00:00.000Z'))->assertOk();   // older: ignored
        expect($flow->hasEnabledFlow($this->shop))->toBeTrue();

        lifecycle($body(false, '2026-09-20T09:30:00.000Z'))->assertOk();
        expect($flow->hasEnabledFlow($this->shop))->toBeFalse();
    });

    it('rejects a bad signature and acknowledges unknown shops', function () {
        $body = ['flow_trigger_definition_id' => 'x', 'has_enabled_flow' => true, 'shopify_domain' => 'unknown.myshopify.com', 'timestamp' => '2026-09-20T09:00:00Z'];

        lifecycle($body, 'bad')->assertUnauthorized();
        lifecycle($body)->assertOk();
        lifecycle(['nope' => 1])->assertStatus(422);
    });
});

it('sends each trigger once per change, with the fields of the extension', function () {
    $acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'email' => 'orders@acme.test', 'lead_time_days' => 14]);
    // 4/day: reorder point 84 > 50 in stock -> due today; runs out in 12 days (threshold 14).
    $mug = product($this->shop, $this->location, 'Mug', stock: 50, perDay: 4, attrs: ['supplier_id' => $acme->id, 'unit_cost' => 2.5, 'sku' => 'MUG-1']);
    product($this->shop, $this->location, 'Vase', stock: 5000, perDay: 1);   // years of stock: nothing
    enableFlow($this->shop);
    forecastNow($this->shop);

    expect(app(FlowTriggerService::class)->send($this->shop))->toBe(3);

    $byHandle = collect($this->sent)->keyBy('handle');
    expect($byHandle->keys()->all())->toBe(['supplier-reorder-date-reached', 'product-reorder-date-reached', 'product-stockout-threshold-reached']);

    expect($byHandle['product-reorder-date-reached']['payload'])->toMatchArray([
        'product_id' => $mug->shopify_product_id,
        'Variant ID' => (string) $mug->shopify_variant_id,
        'Product name' => 'Mug',
        'SKU' => 'MUG-1',
        'Suggested quantity' => 154,   // 4 x (14 + 7 + 30) - 50
        'Reorder date' => '2026-09-20',
        'Days until stockout' => 12,
        'Supplier name' => 'Acme',
        'Supplier email' => 'orders@acme.test',
        'App URL' => 'https://demo.myshopify.com/admin/apps/'.config('shopify.api_key')."/products/{$mug->id}",
    ])->and($byHandle['product-stockout-threshold-reached']['payload'])->toMatchArray(['Threshold days' => 14, 'Days until stockout' => 12])
        ->and($byHandle['supplier-reorder-date-reached']['payload'])->toMatchArray([
            'Supplier name' => 'Acme', 'Products due' => 1, 'New products due' => 1, 'Total units' => 154, 'Total cost' => 385,
            'Currency' => 'USD', 'Order lines' => 'MUG-1 · Mug × 154',
        ]);

    // Next night, nothing changed: quiet.
    expect(app(FlowTriggerService::class)->send($this->shop))->toBe(0);
});

it('sends nothing without an active workflow or below Growth', function () {
    product($this->shop, $this->location, 'Mug', stock: 50, perDay: 4);
    forecastNow($this->shop);
    expect(app(FlowTriggerService::class)->send($this->shop))->toBe(0);

    enableFlow($this->shop);
    $this->shop->update(['plan' => 'starter']);
    expect(app(FlowTriggerService::class)->send($this->shop->fresh()))->toBe(0)
        ->and($this->sent)->toHaveCount(0);
});

it('keeps unsent triggers for the next run when Shopify fails midway', function () {
    product($this->shop, $this->location, 'Mug', stock: 50, perDay: 4);
    enableFlow($this->shop);
    forecastNow($this->shop);

    $this->failAfter = 1;
    expect(fn () => app(FlowTriggerService::class)->send($this->shop))->toThrow(ShopifyApiException::class);

    $this->failAfter = null;
    // The reorder trigger went out; only the stock-out trigger is left.
    expect(app(FlowTriggerService::class)->send($this->shop))->toBe(1)
        ->and(collect($this->sent)->pluck('handle')->all())->toBe(['product-reorder-date-reached', 'product-stockout-threshold-reached']);
});

it('queues the triggers after a forecast run only for shops that use them', function () {
    Queue::fake();
    product($this->shop, $this->location, 'Mug', stock: 50, perDay: 4);

    app(ForecastService::class)->runForShop($this->shop);
    Queue::assertNotPushed(SendFlowTriggers::class);

    enableFlow($this->shop);
    app(ForecastService::class)->runForShop($this->shop);
    Queue::assertPushed(SendFlowTriggers::class, fn ($job) => $job->shopId === $this->shop->id);
});

it('shows the Flow status in settings and warns before a downgrade', function () {
    $this->getJson('/api/settings', $this->auth)->assertJsonPath('data.flow', ['available' => true, 'active' => false]);

    enableFlow($this->shop);
    $this->getJson('/api/settings', $this->auth)->assertJsonPath('data.flow.active', true);
    $this->getJson('/api/billing/impact?plan=starter', $this->auth)->assertJsonFragment(['code' => 'flow_triggers_active']);
});
