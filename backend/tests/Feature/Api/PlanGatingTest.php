<?php

use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'free']);
    $this->location = Location::factory()->for($this->shop)->create(['name' => 'Main warehouse']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['unit_cost' => 3.5]);
    app(ForecastService::class)->runForShop($this->shop);
});

describe('Free plan', function () {
    it('includes the full explanation (transparent forecasts on every plan)', function () {
        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)
            ->assertOk()
            ->assertJsonPath('data.suggested_qty', 194)
            ->assertJsonPath('data.explanation_locked', false)
            ->assertJsonPath('data.explanation_lines.0.code', 'sells_over_window');

        $this->getJson('/api/dashboard', $this->auth)
            ->assertJsonPath('data.explanations_locked', false)
            ->assertJsonPath('data.actions.order_today.0.explanation_lines.0.code', 'sells_over_window');
    });

    it('still hides explanations if a plan is configured without them', function () {
        config(['billing.plans.free.limits.explanations' => false]);

        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)
            ->assertJsonPath('data.explanation', null)
            ->assertJsonPath('data.explanation_locked', true)
            ->assertJsonPath('data.computed_avg', 4);
    });

    it('cannot create bundles, export purchase orders or see stock by location', function () {
        $box = Variant::factory()->for($this->shop)->create();

        $this->postJson('/api/bundles', ['bundle' => $box->id, 'components' => [['variant' => $this->mug->id, 'quantity' => 1]]], $this->auth)
            ->assertStatus(402)->assertJsonPath('code', 'plan_required')->assertJsonPath('params.plan', 'starter');
        $this->get('/api/purchase-orders/export', $this->auth)->assertStatus(402)->assertJsonPath('params.plan', 'growth')->assertJsonPath('params.feature', 'purchase_orders');
        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertJsonPath('data.locations', null);
        $this->getJson("/api/forecasts?location_id={$this->location->id}", $this->auth)->assertStatus(402);
    });

    it('reports alerts as unavailable and exposes entitlements', function () {
        $this->getJson('/api/settings', $this->auth)->assertJsonPath('data.alerts.available', false);
        $this->getJson('/api/shop', $this->auth)
            ->assertJsonPath('data.entitlements.plan', 'free')
            ->assertJsonPath('data.entitlements.max_skus', 50)
            ->assertJsonPath('data.entitlements.explanations', true)
            ->assertJsonPath('data.entitlements.alerts', false);
    });

    it('tells the merchant how many products are not forecast', function () {
        config(['billing.plans.free.limits.max_skus' => 1]);
        product($this->shop, $this->location, 'Plate', stock: 10, perDay: 1);
        app(ForecastService::class)->runForShop($this->shop);

        $this->getJson('/api/dashboard', $this->auth)
            ->assertJsonPath('data.counts.total', 1)
            ->assertJsonPath('data.counts.tracked', 2);
    });
});

describe('Growth plan', function () {
    beforeEach(fn () => $this->shop->update(['plan' => 'growth']));

    it('shows stock by location', function () {
        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)
            ->assertJsonPath('data.locations.0.location', 'Main warehouse')
            ->assertJsonPath('data.locations.0.available', 10);
    });

    it('exports a purchase order for the products picked on the home screen', function () {
        // Not due yet (reorder in 9 days), but picked from "this week": still exported.
        $plate = product($this->shop, $this->location, 'Plate', stock: 30, perDay: 1);
        app(ForecastService::class)->runForShop($this->shop);

        $csv = $this->get("/api/purchase-orders/export?variant_ids={$plate->id}", $this->auth)->assertOk()->streamedContent();

        expect($csv)->toContain('Plate')->not->toContain('Mug');
        $this->getJson('/api/purchase-orders/export?variant_ids=1;DROP', $this->auth)->assertUnprocessable();
    });

    it('exports a purchase order CSV of what to reorder now, per supplier', function () {
        $acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme Ceramics']);
        $this->mug->update(['supplier_id' => $acme->id, 'sku' => 'MUG-1']);
        product($this->shop, $this->location, 'Plate', stock: 5000, perDay: 1); // plenty of stock: not on the PO

        $response = $this->get("/api/purchase-orders/export?supplier_id={$acme->id}", $this->auth)->assertOk();

        expect($response->headers->get('content-disposition'))->toContain('purchase-order-acme-ceramics-2026-09-20.csv');
        $lines = array_map('str_getcsv', explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
        expect($lines)->toHaveCount(2)
            ->and($lines[1])->toBe(['All locations', 'Acme Ceramics', 'Mug', 'MUG-1', '10', '4', '2026-09-22', '194', '3.5', '679', 'USD']);
    });
});
