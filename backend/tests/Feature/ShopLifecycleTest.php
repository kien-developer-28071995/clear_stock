<?php

use App\Enums\Plan;
use App\Events\ShopUninstalled;
use App\Models\AlertLog;
use App\Models\AlertSetting;
use App\Models\BundleComponent;
use App\Models\DailySale;
use App\Models\Forecast;
use App\Models\ForecastOverride;
use App\Models\InventoryLevel;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** Create one row in every shop-owned table. */
function seedShopData(Shop $shop): void
{
    $supplier = Supplier::factory()->for($shop)->create();
    $variant = Variant::factory()->for($shop)->create(['supplier_id' => $supplier->id]);
    $bundle = Variant::factory()->for($shop)->bundle()->create();
    BundleComponent::factory()->create(['shop_id' => $shop->id, 'bundle_variant_id' => $bundle->id, 'component_variant_id' => $variant->id]);
    InventoryLevel::factory()->create(['variant_id' => $variant->id]);
    DailySale::factory()->count(3)->create(['variant_id' => $variant->id]);
    Forecast::factory()->create(['variant_id' => $variant->id]);
    ForecastOverride::factory()->create(['variant_id' => $variant->id]);
    AlertSetting::factory()->for($shop)->create();
    AlertLog::create(['shop_id' => $shop->id, 'variant_id' => $variant->id, 'type' => 'reorder_needed', 'sent_at' => now()]);
}

const SHOP_TABLES = ['suppliers', 'variants', 'bundle_components', 'locations', 'inventory_levels', 'daily_sales',
    'forecasts', 'forecast_overrides', 'alert_settings', 'alert_logs'];

it('app/uninstalled drops tokens, resets the plan and keeps data for a quick reinstall', function () {
    Event::fake([ShopUninstalled::class]);
    $shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'plan' => Plan::Starter]);
    seedShopData($shop);

    postWebhook('app/uninstalled')->assertOk(); // queue is sync in tests

    $shop->refresh();
    expect($shop->uninstalled_at)->not->toBeNull()
        ->and($shop->access_token)->toBeNull()
        ->and($shop->refresh_token)->toBeNull()
        ->and($shop->plan)->toBe(Plan::Free)
        ->and($shop->isInstalled())->toBeFalse()
        ->and(Variant::forShop($shop)->count())->toBe(2);
    Event::assertDispatched(ShopUninstalled::class);
});

it('app/uninstalled is idempotent and ignores unknown shops', function () {
    $shop = Shop::factory()->uninstalled()->create(['domain' => 'demo.myshopify.com']);
    $at = $shop->uninstalled_at;

    postWebhook('app/uninstalled')->assertOk();
    postWebhook('app/uninstalled', shop: 'unknown.myshopify.com')->assertOk();

    expect($shop->fresh()->uninstalled_at->equalTo($at))->toBeTrue();
});

it('shop/redact deletes every row of that shop and nothing else', function () {
    $shop = Shop::factory()->uninstalled()->create(['domain' => 'demo.myshopify.com']);
    $other = Shop::factory()->create(['domain' => 'other.myshopify.com']);
    seedShopData($shop);
    seedShopData($other);

    postWebhook('shop/redact', ['shop_id' => 1, 'shop_domain' => 'demo.myshopify.com'])->assertOk();

    expect(Shop::where('domain', 'demo.myshopify.com')->exists())->toBeFalse();
    foreach (SHOP_TABLES as $table) {
        expect(DB::table($table)->where('shop_id', $shop->id)->count())->toBe(0, "{$table} not purged")
            ->and(DB::table($table)->where('shop_id', $other->id)->count())->toBeGreaterThan(0, "{$table} of other shop touched");
    }
});

it('shop/redact is skipped when the shop has reinstalled since', function () {
    $shop = Shop::factory()->create(['domain' => 'demo.myshopify.com']);
    seedShopData($shop);

    postWebhook('shop/redact')->assertOk();

    expect($shop->fresh())->not->toBeNull()
        ->and(Variant::forShop($shop)->count())->toBe(2);
});

it('shop/redact for an unknown shop succeeds', function () {
    postWebhook('shop/redact', shop: 'never-installed.myshopify.com')->assertOk();
});

it('customer compliance webhooks succeed (no customer data is stored)', function (string $topic) {
    Shop::factory()->create(['domain' => 'demo.myshopify.com']);

    postWebhook($topic, ['customer' => ['id' => 1, 'email' => 'a@b.c'], 'orders_to_redact' => [1]])->assertOk();
})->with(['customers/data_request', 'customers/redact']);

it('app/scopes_update stores the granted scopes', function () {
    $shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'scopes' => 'read_products']);

    postWebhook('app/scopes_update', ['previous' => ['read_products'], 'current' => ['read_products', 'read_orders']])->assertOk();

    expect($shop->fresh()->scopes)->toBe('read_products,read_orders');
});
