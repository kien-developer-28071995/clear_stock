<?php

use App\Enums\Feature;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\App\SupplierEmailService;
use App\Services\Flow\FlowTriggerService;
use App\Services\Forecast\ForecastService;
use App\Support\Entitlements;
use App\Support\Features;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/graphql.json' => Http::response(['data' => ['shop' => ['contactEmail' => 'owner@demo.test']]])]);
    $this->travelTo('2026-09-20 10:00:00');
    // Growth: everything is on by plan, so only the switches decide.
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'growth']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

function switchOff(string ...$switches): void
{
    foreach ($switches as $switch) {
        config(["features.{$switch}" => false]);
    }
}

it('has every feature on by default', function () {
    expect(array_unique(array_values(Features::all())))->toBe([true]);
    $this->getJson('/api/shop', $this->auth)->assertJsonPath('data.entitlements.what_if', true)->assertJsonPath('data.entitlements.features.flow_triggers', true);
});

it('turns a feature off for every plan: 404 feature_disabled, hidden in entitlements and on the pricing page', function () {
    switchOff('what_if');

    $this->getJson('/api/what-if?growth=20', $this->auth)->assertNotFound()->assertJsonPath('code', 'feature_disabled')->assertJsonPath('params.feature', 'what_if');
    $this->getJson('/api/shop', $this->auth)
        ->assertJsonPath('data.entitlements.what_if', false)
        ->assertJsonPath('data.entitlements.features.what_if', false)
        ->assertJsonPath('data.entitlements.features.abc', true);
    expect(collect($this->getJson('/api/billing', $this->auth)->json('data.plans'))->pluck('limits.what_if')->unique()->all())->toBe([false]);
});

it('switches transfers off with locations, and hides per-location data', function () {
    switchOff('locations');

    expect(Features::enabled(Feature::Transfers))->toBeFalse()
        ->and(Features::all()['transfers'])->toBeFalse();
    $this->getJson('/api/transfers', $this->auth)->assertNotFound()->assertJsonPath('code', 'feature_disabled');
    $this->getJson("/api/forecasts?location_id={$this->location->id}", $this->auth)->assertNotFound();
    $this->getJson('/api/locations', $this->auth)->assertJsonPath('data', []);
});

it('stops emailing suppliers, by hand and automatically', function () {
    switchOff('supplier_emails');
    $acme = Supplier::factory()->for($this->shop)->create(['email' => 'orders@acme.test', 'auto_email' => true]);

    $this->getJson("/api/suppliers/{$acme->id}/email", $this->auth)->assertNotFound();
    $this->putJson("/api/suppliers/{$acme->id}", ['name' => $acme->name, 'auto_email' => true], $this->auth)->assertNotFound();
    expect(app(SupplierEmailService::class)->sendDue($this->shop))->toBe(0);
});

it('hides ABC classes everywhere and ignores ABC filters', function () {
    $mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['price' => 10]);
    $cup = product($this->shop, $this->location, 'Cup', stock: 10, perDay: 1, attrs: ['price' => 1]);
    app(ForecastService::class)->runForShop($this->shop);
    switchOff('abc');

    $this->getJson('/api/forecasts?abc=C&sort=revenue', $this->auth)->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.abc_class', null);
    $this->getJson("/api/forecasts/{$mug->id}", $this->auth)->assertJsonPath('data.abc', null);
    $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.abc', null);
});

it('stops Flow triggers, real-time alerts, purchase orders and reference products', function () {
    switchOff('flow_triggers', 'realtime_alerts', 'purchase_orders', 'reference_products');
    $mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
    $cup = product($this->shop, $this->location, 'Cup', stock: 10, perDay: 4);

    expect(app(FlowTriggerService::class)->shouldSend($this->shop))->toBeFalse();
    $this->getJson('/api/settings', $this->auth)
        ->assertJsonPath('data.flow.available', false)
        ->assertJsonPath('data.alerts.realtime_available', false)
        ->assertJsonPath('data.alerts.available', true);   // core feature, not switchable
    $this->get('/api/purchase-orders/export', $this->auth)->assertNotFound();
    $this->putJson("/api/variants/{$mug->id}/settings", ['reference_variant' => $cup->id], $this->auth)->assertNotFound();
    // Clearing a reference stays possible.
    $this->putJson("/api/variants/{$mug->id}/settings", ['reference_variant' => null], $this->auth)->assertOk();
});

it('leaves features off the downgrade warning when they are switched off', function () {
    switchOff('flow_triggers', 'locations');

    $codes = collect($this->getJson('/api/billing/impact?plan=starter', $this->auth)->json('data.lost'))->pluck('code');
    expect($codes)->not->toContain('flow_triggers')->not->toContain('locations');
});

it('can stop offering Growth without touching Growth subscribers', function () {
    config(['billing.plans.growth.offered' => false]);
    $starter = Shop::factory()->create(['domain' => 'other.myshopify.com', 'plan' => 'starter']);

    $this->getJson('/api/billing', $this->auth)->assertJsonPath('data.plans.2.offered', false);
    $this->postJson('/api/billing', ['plan' => 'growth', 'interval' => 'monthly'], ['Authorization' => 'Bearer '.sessionToken('other.myshopify.com')])
        ->assertUnprocessable()->assertJsonPath('code', 'plan_unavailable');
    // The Growth shop keeps its plan and features.
    expect(Entitlements::for($this->shop)->has(Feature::Locations))->toBeTrue();
});

it('reports the switches and warns about combinations that cannot work', function () {
    config(['shopify.scopes' => 'read_products,read_inventory,read_locations,read_orders,read_all_orders,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders']);
    $this->artisan('features:status')->assertSuccessful();

    switchOff('locations', 'realtime_alerts', 'flow_triggers', 'supplier_emails');
    $this->artisan('features:status')
        ->expectsOutputToContain('Growth is offered but every Growth-only feature is off')
        ->assertFailed();

    config(['billing.plans.growth.offered' => false]);
    $this->artisan('features:status')->assertSuccessful();

    config(['features.locations' => true, 'shopify.scopes' => 'read_products,read_orders']);
    $this->artisan('features:status')->expectsOutputToContain('SHOPIFY_SCOPES lacks')->assertFailed();
});
