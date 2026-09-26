<?php

use App\Models\AlertSetting;
use App\Models\BundleComponent;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Before moving to a smaller plan the merchant sees what stops working, measured on
// the shop's own data. Nothing is deleted: upgrading again brings it all back.

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'plan' => 'growth']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    Location::factory()->count(2)->for($this->shop)->create();
    Variant::factory()->count(53)->for($this->shop)->create(['tracked' => true, 'is_active' => true]);
    $box = Variant::factory()->for($this->shop)->create(['is_bundle' => true]);
    BundleComponent::factory()->create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $box->id, 'component_variant_id' => Variant::first()->id, 'source' => 'manual']);
    AlertSetting::factory()->for($this->shop)->create(['enabled' => true, 'email' => 'owner@demo.test', 'realtime' => 'out_of_stock']);
    Supplier::factory()->for($this->shop)->create(['email' => 'a@x.test', 'auto_email' => true]);
    Supplier::factory()->for($this->shop)->create(['email' => 'b@x.test', 'auto_email' => false]);
});

it('lists what Growth to Free takes away, with the shop numbers', function () {
    $this->getJson('/api/billing/impact?plan=free', $this->auth)
        ->assertOk()
        ->assertJsonPath('data.downgrade', true)
        ->assertJsonPath('data.lost', [
            ['code' => 'forecast_limit', 'params' => ['limit' => 50, 'count' => 4]], // 54 tracked products
            ['code' => 'bundles', 'params' => ['count' => 1]],
            ['code' => 'alerts_active', 'params' => ['email' => 'owner@demo.test']],
            ['code' => 'realtime_alerts_active', 'params' => ['email' => 'owner@demo.test']],
            ['code' => 'locations', 'params' => ['count' => 2]],
            ['code' => 'purchase_orders', 'params' => []],
            ['code' => 'supplier_auto_emails', 'params' => ['count' => 1]],
            ['code' => 'flow_triggers', 'params' => []],
            ['code' => 'what_if', 'params' => []],
        ]);
});

it('lists only the Growth features when moving to Starter', function () {
    // Purchase order export, emailing an order by hand and the what-if stay on Starter.
    $this->getJson('/api/billing/impact?plan=starter', $this->auth)
        ->assertJsonPath('data.lost.*.code', ['realtime_alerts_active', 'locations', 'supplier_auto_emails', 'flow_triggers']);
});

it('has nothing to warn about on upgrades or the same plan', function () {
    $this->shop->update(['plan' => 'starter']);

    $this->getJson('/api/billing/impact?plan=growth', $this->auth)->assertJsonPath('data', ['downgrade' => false, 'lost' => []]);
    $this->getJson('/api/billing/impact?plan=starter', $this->auth)->assertJsonPath('data.downgrade', false);
    $this->getJson('/api/billing/impact?plan=gold', $this->auth)->assertUnprocessable();
});
