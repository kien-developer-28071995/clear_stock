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

it('has a switch for every optional feature and closes the API of one that is off', function (string $switch, string $method, string $url) {
    expect(config('features'))->toHaveKey($switch);
    switchOff($switch);

    $this->json($method, $url, [], $this->auth)->assertNotFound()->assertJsonPath('code', 'feature_disabled');
    expect($this->getJson('/api/shop', $this->auth)->json("data.entitlements.features.{$switch}"))->toBeFalse();
})->with([
    ['product_export', 'GET', '/api/forecasts/export'],
    ['saved_views', 'GET', '/api/views'],
    ['clearance', 'GET', '/api/clearance'],
    ['size_runs', 'GET', '/api/size-runs'],
    ['location_exclusions', 'GET', '/api/settings/locations'],
    ['vendor_suppliers', 'GET', '/api/suppliers/from-vendors'],
    ['costs', 'GET', '/api/costs'],
    ['manual_orders', 'GET', '/api/manual-orders'],
    ['stock_history', 'GET', '/api/stock-history'],
    ['data_health', 'GET', '/api/data-health'],
    ['snooze', 'POST', '/api/snooze'],
    ['change_log', 'GET', '/api/forecasts/1/changes'],
    ['alternate_suppliers', 'GET', '/api/variants/1/suppliers'],
    ['supplier_import', 'POST', '/api/imports/purchase-orders/preview'],
    ['bundles', 'GET', '/api/bundles'],
]);

it('switches alerts off together with the Slack and days-left options that need them', function () {
    config(['features.alerts' => false]);

    expect(Features::all())->toMatchArray(['alerts' => false, 'slack_alerts' => false, 'low_cover_alerts' => false])
        ->and(Features::enabled(Feature::Alerts))->toBeFalse();
});

it('leaves the alerts step and the vendor shortcut out of the setup guide when they are switched off', function () {
    $keys = fn () => array_column($this->getJson('/api/setup-guide', $this->auth)->assertOk()->json('data.steps'), 'key');
    expect($keys())->toContain('alerts');

    switchOff('alerts', 'vendor_suppliers');

    $guide = $this->getJson('/api/setup-guide', $this->auth)->assertOk()->json('data');
    expect(array_column($guide['steps'], 'key'))->not->toContain('alerts')
        ->and($guide['total'])->toBe(count($guide['steps']))
        ->and($guide['context']['vendor_count'])->toBe(0);
});

it('hides bundles and alerts on every plan when switched off', function () {
    switchOff('bundles', 'alerts');

    $entitlements = $this->getJson('/api/shop', $this->auth)->json('data.entitlements');
    expect($entitlements['bundles'])->toBeFalse()->and($entitlements['alerts'])->toBeFalse()
        ->and(Entitlements::for($this->shop)->has(Feature::Bundles))->toBeFalse();
    $this->putJson('/api/settings', ['alerts' => ['enabled' => true]], $this->auth);
    expect($this->getJson('/api/settings', $this->auth)->json('data.alerts.available'))->toBeFalse();
});

it('lists every switch in the Makefile lists the E2E runs and the v1 screenshots use', function () {
    $makefile = file_get_contents(base_path('../Makefile'));
    preg_match('/^ALL_OFF = (.*)$/m', $makefile, $all);
    preg_match('/^V1_OFF = (.*)$/m', $makefile, $v1);
    $example = file_get_contents(base_path('.env.production.example'));

    foreach (array_keys(config('features')) as $switch) {
        $env = 'FEATURE_'.strtoupper($switch);
        expect($all[1])->toContain("{$env}=false")
            ->and($example)->toMatch("/^{$env}=(true|false)$/m");
        // v1: the Makefile and the production template switch off the same features.
        expect(str_contains($v1[1], "{$env}=false"))->toBe((bool) preg_match("/^{$env}=false$/m", $example), $env);
    }
})->skip(fn () => ! is_file(base_path('../Makefile')), 'the Makefile is outside the backend image');
