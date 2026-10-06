<?php

use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Services\ShopLifecycleService;
use App\Support\Csv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('leaves no row of the shop in any table after shop/redact, including the newer tables', function () {
    $shop = Shop::factory()->create(['domain' => 'gone.myshopify.com', 'uninstalled_at' => now()->subDays(3), 'access_token' => null]);
    $keep = Shop::factory()->create();
    $variant = Variant::factory()->for($shop)->create();
    $location = Location::factory()->for($shop)->create();
    $supplier = Supplier::factory()->for($shop)->create();
    $now = now();
    DB::table('web_vitals')->insert(['shop_id' => $shop->id, 'metric' => 'LCP', 'value' => 1200, 'page' => '/', 'created_at' => $now]);
    DB::table('saved_views')->insert(['shop_id' => $shop->id, 'name' => 'Mine', 'filters' => '{}', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('inventory_snapshots')->insert(['shop_id' => $shop->id, 'date' => '2026-09-01', 'units' => 1, 'value' => 1, 'products_in_stock' => 1, 'products_missing_cost' => 0, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('location_minimums')->insert(['shop_id' => $shop->id, 'variant_id' => $variant->id, 'location_id' => $location->id, 'min_stock' => 5, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('variant_suppliers')->insert(['shop_id' => $shop->id, 'variant_id' => $variant->id, 'supplier_id' => $supplier->id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('change_logs')->insert(['shop_id' => $shop->id, 'variant_id' => $variant->id, 'field' => 'min_stock', 'new_value' => '5', 'source' => 'app', 'created_at' => $now]);
    DB::table('feature_events')->insert(['shop_id' => $shop->id, 'feature' => 'what_if', 'day' => '2026-09-01', 'count' => 2]);
    DB::table('saved_views')->insert(['shop_id' => $keep->id, 'name' => 'Theirs', 'filters' => '{}', 'created_at' => $now, 'updated_at' => $now]);

    app(ShopLifecycleService::class)->redactShop('gone.myshopify.com');

    $left = [];
    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (Schema::hasColumn($table, 'shop_id') && DB::table($table)->where('shop_id', $shop->id)->exists()) {
            $left[] = $table;
        }
    }
    expect($left)->toBe([])
        ->and(Shop::query()->whereKey($shop->id)->exists())->toBeFalse()
        ->and(DB::table('saved_views')->where('shop_id', $keep->id)->count())->toBe(1);
});

it('keeps spreadsheet formulas out of CSV exports', function () {
    expect(Csv::safe(['=SUM(A1:A9)', '+1 item', '-5', -5, '@cmd', 'Mug', '', null, 12.5, '-not a number']))
        ->toBe(["'=SUM(A1:A9)", "'+1 item", '-5', -5, "'@cmd", 'Mug', '', null, 12.5, "'-not a number"]);
});

it('bounds what a saved view can hold', function () {
    Shop::factory()->create(['domain' => 'demo.myshopify.com', 'access_token_expires_at' => now()->addYear()]);
    $auth = ['Authorization' => 'Bearer '.sessionToken()];

    $this->postJson('/api/views', ['name' => 'Big', 'filters' => ['vendor' => str_repeat('x', 300)]], $auth)->assertStatus(422);
    $this->postJson('/api/views', ['name' => str_repeat('n', 61), 'filters' => []], $auth)->assertStatus(422);
});
