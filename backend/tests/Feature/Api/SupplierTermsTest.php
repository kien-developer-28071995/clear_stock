<?php

use App\Models\BundleComponent;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => 14]);
    // 4/day, stock 20: due now, order 184 (4 x 51 - 20).
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 20, perDay: 4, attrs: ['sku' => 'MUG', 'supplier_id' => $this->acme->id, 'unit_cost' => 2, 'shopify_unit_cost' => 2]);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 5000, perDay: 1, attrs: ['sku' => 'CUP', 'unit_cost' => 3, 'shopify_unit_cost' => 3]);
    $this->run = fn () => app(ForecastService::class)->runForShop($this->shop->fresh());
    ($this->run)();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('adds the supplier\'s landed cost share to money figures but not to purchase orders', function () {
    $this->putJson("/api/suppliers/{$this->acme->id}", ['name' => 'Acme', 'landed_cost_percent' => 25], $this->auth)->assertOk()->assertJsonPath('data.landed_cost_percent', 25);
    ($this->run)();

    expect((float) $this->mug->fresh()->unit_cost)->toBe(2.5)->and($this->mug->fresh()->landed_cost_applied)->toBeTrue()
        ->and((float) $this->cup->fresh()->unit_cost)->toBe(3.0);           // another supplier's product: untouched
    $csv = $this->get('/api/purchase-orders/export?format=shopify', $this->auth)->streamedContent();
    expect($csv)->toContain('MUG,,,184,2.00,');                              // the supplier is paid its own price

    // A cost entered in the app is the new base; clearing the share restores the plain cost.
    $this->putJson('/api/costs', ['items' => [['variant_id' => $this->mug->id, 'cost' => 4]]], $this->auth)->assertOk();
    expect((float) $this->mug->fresh()->unit_cost)->toBe(5.0);
    $this->putJson("/api/suppliers/{$this->acme->id}", ['name' => 'Acme', 'landed_cost_percent' => null], $this->auth)->assertOk();
    ($this->run)();
    expect((float) $this->mug->fresh()->unit_cost)->toBe(4.0)->and($this->mug->fresh()->landed_cost_applied)->toBeFalse();
});

it('drops the landed cost when a product leaves the supplier', function () {
    $this->acme->update(['landed_cost_percent' => 10]);
    ($this->run)();
    expect((float) $this->mug->fresh()->unit_cost)->toBe(2.2);

    $this->putJson("/api/variants/{$this->mug->id}/settings", ['supplier_id' => null], $this->auth)->assertOk();
    expect((float) $this->mug->fresh()->unit_cost)->toBe(2.0);
});

it('shows what is due per supplier next to its minimum order value', function () {
    $this->putJson("/api/suppliers/{$this->acme->id}", ['name' => 'Acme', 'min_order_value' => 500], $this->auth)->assertOk()->assertJsonPath('data.min_order_value', 500);

    $supplier = $this->getJson('/api/suppliers', $this->auth)->assertOk()->json('data.0');
    expect($supplier['due'])->toEqual(['products' => 1, 'units' => 184, 'cost' => 368])   // 184 x $2: below the $500 minimum
        ->and($supplier['min_order_value'])->toEqual(500);
    $this->putJson("/api/suppliers/{$this->acme->id}", ['name' => 'Acme', 'min_order_value' => -1], $this->auth)->assertStatus(422);
});

it('says how many bundles the components on hand make', function () {
    $set = product($this->shop, $this->location, 'Gift set', stock: 0, perDay: 0, attrs: ['is_bundle' => true]);
    BundleComponent::factory()->create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $set->id, 'component_variant_id' => $this->mug->id, 'quantity' => 3, 'source' => 'manual']);
    BundleComponent::factory()->create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $set->id, 'component_variant_id' => $this->cup->id, 'quantity' => 1, 'source' => 'manual']);

    $bundle = $this->getJson('/api/bundles', $this->auth)->assertOk()->json('data.0');
    expect($bundle['buildable'])->toBe(6)                       // 20 mugs / 3
        ->and($bundle['limiting_component'])->toBe('Mug')
        ->and(array_column($bundle['components'], 'stock'))->toBe([20, 5000]);
});
