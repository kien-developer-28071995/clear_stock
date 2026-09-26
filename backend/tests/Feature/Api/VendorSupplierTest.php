<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use App\Services\Sync\VariantImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Suppliers from the Vendor field of Shopify products, in one step.

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'name' => 'Demo Store', 'plan' => 'free']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $make = fn (?string $vendor, int $n, array $attrs = []) => Variant::factory()->count($n)->for($this->shop)->create($attrs + ['vendor' => $vendor, 'tracked' => true, 'is_active' => true]);
    $this->acme = $make('Acme Ceramics', 3, ['product_type' => 'Mugs']);
    $this->glass = $make('Glass Co', 2, ['product_type' => 'Glasses']);
    $make('Demo Store', 1);              // own brand
    $make(null, 2);                       // no vendor
    $make('Acme Ceramics', 1, ['tracked' => false]); // not tracked: ignored
});

it('imports vendor and product type from Shopify', function () {
    app(VariantImporter::class)->import($this->shop, jsonlFile([[
        'id' => gid('ProductVariant', 77), 'sku' => 'X', 'title' => 'Default Title', 'createdAt' => '2025-03-01T10:00:00Z', 'requiresComponents' => false,
        'product' => ['id' => gid('Product', 7), 'title' => 'Teapot', 'status' => 'ACTIVE', 'vendor' => '  Acme Ceramics ', 'productType' => ''],
        'inventoryItem' => ['id' => gid('InventoryItem', 770), 'tracked' => true, 'unitCost' => null],
    ]]));

    expect(Variant::where('shopify_variant_id', 77)->first()->only(['vendor', 'product_type']))->toBe(['vendor' => 'Acme Ceramics', 'product_type' => null]);
});

it('previews each vendor with its products, matching supplier and own-brand flag', function () {
    Supplier::factory()->for($this->shop)->create(['name' => 'glass co']);
    $this->acme[0]->update(['supplier_id' => Supplier::first()->id]);

    $this->getJson('/api/suppliers/from-vendors', $this->auth)
        ->assertOk()
        ->assertJsonPath('data.vendors', [
            ['vendor' => 'Acme Ceramics', 'products' => 3, 'with_supplier' => 1, 'supplier' => null, 'is_store_name' => false],
            ['vendor' => 'Glass Co', 'products' => 2, 'with_supplier' => 0, 'supplier' => ['id' => Supplier::first()->id, 'name' => 'glass co'], 'is_store_name' => false],
            ['vendor' => 'Demo Store', 'products' => 1, 'with_supplier' => 0, 'supplier' => null, 'is_store_name' => true],
        ]);
});

it('creates or reuses suppliers and links products, keeping existing links unless asked', function () {
    $other = Supplier::factory()->for($this->shop)->create(['name' => 'Other']);
    $existing = Supplier::factory()->for($this->shop)->create(['name' => 'GLASS CO']);
    $this->acme[0]->update(['supplier_id' => $other->id]);

    $this->postJson('/api/suppliers/from-vendors', ['vendors' => ['Acme Ceramics', 'Glass Co', 'Nope Inc']], $this->auth)
        ->assertOk()
        ->assertJsonPath('data', ['suppliers_created' => 1, 'suppliers_reused' => 1, 'products_assigned' => 4, 'products_kept' => 1]);

    $acme = Supplier::where('name', 'Acme Ceramics')->sole();
    expect($this->acme[0]->fresh()->supplier_id)->toBe($other->id)
        ->and($this->acme[1]->fresh()->supplier_id)->toBe($acme->id)
        ->and($this->glass->every(fn ($v) => $v->fresh()->supplier_id === $existing->id))->toBeTrue()
        ->and(Supplier::count())->toBe(3);
    Queue::assertPushed(RecomputeForecasts::class);

    $this->postJson('/api/suppliers/from-vendors', ['vendors' => ['Acme Ceramics'], 'replace_existing' => true], $this->auth)
        ->assertJsonPath('data', ['suppliers_created' => 0, 'suppliers_reused' => 1, 'products_assigned' => 1, 'products_kept' => 0]);
    expect($this->acme[0]->fresh()->supplier_id)->toBe($acme->id);
});

it('filters the product list by vendor and product type, and lists them for the filters', function () {
    Queue::fake();
    app(ForecastService::class)->runForShop($this->shop);

    $this->getJson('/api/facets', $this->auth)
        ->assertJsonPath('data', ['vendors' => ['Acme Ceramics', 'Demo Store', 'Glass Co'], 'product_types' => ['Glasses', 'Mugs']]);
    $this->getJson('/api/forecasts?vendor=Glass%20Co', $this->auth)->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.vendor', 'Glass Co');
    $this->getJson('/api/forecasts?product_type=Mugs', $this->auth)->assertJsonPath('meta.total', 3);
});

it('offers vendors in the setup guide', function () {
    $this->getJson('/api/setup-guide', $this->auth)->assertJsonPath('data.context.vendor_count', 3);
});
