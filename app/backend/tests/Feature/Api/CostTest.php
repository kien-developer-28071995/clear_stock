<?php

use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Services\Forecast\ForecastService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'free']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    // Slow movers (5 years of stock): the cash tied up shows on the dashboard.
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 900, perDay: 1, attrs: ['sku' => 'MUG-1', 'unit_cost' => null, 'shopify_unit_cost' => null]);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 900, perDay: 1, attrs: ['sku' => 'CUP-1', 'barcode' => '4006381333931', 'unit_cost' => 2, 'shopify_unit_cost' => 2]);
    app(ForecastService::class)->runForShop($this->shop);
});

it('lists products missing a cost and sets costs in bulk; money figures follow', function () {
    $this->getJson('/api/costs?missing=1', $this->auth)->assertOk()
        ->assertJsonPath('data.counts', ['tracked' => 2, 'missing' => 1, 'overridden' => 0])
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.name', 'Mug');
    $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.slow_movers.value', 1800)->assertJsonPath('data.slow_movers.missing_cost', 1);

    $this->putJson('/api/costs', ['items' => [['variant_id' => $this->mug->id, 'cost' => 3.5], ['variant_id' => $this->cup->id, 'cost' => 2.5]]], $this->auth)
        ->assertOk()->assertJsonPath('data.updated', 2);

    expect((float) $this->mug->fresh()->unit_cost)->toBe(3.5)->and((float) $this->cup->fresh()->unit_cost)->toBe(2.5);
    $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.slow_movers.value', 5400)->assertJsonPath('data.slow_movers.missing_cost', 0);

    // Clearing the app cost goes back to Shopify's.
    $this->putJson('/api/costs', ['items' => [['variant_id' => $this->cup->id, 'cost' => null]]], $this->auth)->assertOk();
    expect((float) $this->cup->fresh()->unit_cost)->toBe(2.0);
});

it('keeps the app cost when the sync brings Shopify costs', function () {
    $this->putJson('/api/costs', ['items' => [['variant_id' => $this->cup->id, 'cost' => 2.75]]], $this->auth)->assertOk();

    app(CatalogRepositoryInterface::class)->upsertVariants($this->shop, [
        ['shopify_variant_id' => $this->cup->shopify_variant_id, 'shopify_product_id' => $this->cup->shopify_product_id, 'inventory_item_id' => null,
            'product_title' => 'Cup', 'title' => 'Default Title', 'vendor' => null, 'product_type' => null, 'sku' => 'CUP-1', 'barcode' => null,
            'shopify_unit_cost' => 4, 'price' => null, 'tracked' => true, 'is_active' => true, 'shopify_created_at' => '2025-01-01 00:00:00'],
        ['shopify_variant_id' => $this->mug->shopify_variant_id, 'shopify_product_id' => $this->mug->shopify_product_id, 'inventory_item_id' => null,
            'product_title' => 'Mug', 'title' => 'Default Title', 'vendor' => null, 'product_type' => null, 'sku' => 'MUG-1', 'barcode' => null,
            'shopify_unit_cost' => 1.25, 'price' => null, 'tracked' => true, 'is_active' => true, 'shopify_created_at' => '2025-01-01 00:00:00'],
    ]);

    expect((float) $this->cup->fresh()->unit_cost)->toBe(2.75)->and((float) $this->cup->fresh()->shopify_unit_cost)->toBe(4.0)
        ->and((float) $this->mug->fresh()->unit_cost)->toBe(1.25);
});

it('imports costs from a CSV by SKU or barcode (a Shopify product export works)', function () {
    $csv = "Handle,Variant SKU,Variant Barcode,Cost per item\nmug,MUG-1,,\"1,234.50\"\ncup,,4006381333931,\"2,40\"\nx,NOPE-9,,5\ny,MUG-1,,abc\n";
    $file = UploadedFile::fake()->createWithContent('products_export.csv', $csv);

    $this->post('/api/costs/import', ['file' => $file], $this->auth + ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data', ['updated' => 2, 'unmatched' => 1, 'invalid' => 1, 'unmatched_examples' => ['NOPE-9']]);
    expect((float) $this->mug->fresh()->unit_cost)->toBe(1234.5)->and((float) $this->cup->fresh()->unit_cost)->toBe(2.4);

    $bad = UploadedFile::fake()->createWithContent('x.csv', "Name,Qty\nMug,3\n");
    $this->post('/api/costs/import', ['file' => $bad], $this->auth + ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonPath('errors.file.0.code', 'cost_columns_missing');
});

it('sets the app cost from the product settings', function () {
    $this->putJson("/api/variants/{$this->mug->id}/settings", ['cost_override' => 6], $this->auth)->assertOk()->assertJsonPath('data.cost_override', '6.0000');
    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertJsonPath('data.settings.cost_override', 6)->assertJsonPath('data.unit_cost', 6);
    expect(Variant::find($this->mug->id)->unit_cost)->toBe('6.0000');
});
