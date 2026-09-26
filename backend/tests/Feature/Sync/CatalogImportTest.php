<?php

use App\Models\BundleComponent;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Services\Sync\InventoryImporter;
use App\Services\Sync\VariantImporter;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->shop = Shop::factory()->create();
});

function variantLine(int $id, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => gid('ProductVariant', $id),
        'sku' => "SKU-{$id}",
        'title' => 'Default Title',
        'createdAt' => '2025-03-01T10:00:00Z',
        'requiresComponents' => false,
        'product' => ['id' => gid('Product', $id * 10), 'title' => "Product {$id}", 'status' => 'ACTIVE'],
        'inventoryItem' => ['id' => gid('InventoryItem', $id * 100), 'tracked' => true, 'unitCost' => ['amount' => '4.50']],
    ], $overrides);
}

it('imports variants and keeps merchant settings on re-import', function () {
    $supplier = Supplier::factory()->for($this->shop)->create();
    app(VariantImporter::class)->import($this->shop, jsonlFile([variantLine(1), variantLine(2)]));

    $v = Variant::forShop($this->shop)->where('shopify_variant_id', 1)->first();
    expect($v->sku)->toBe('SKU-1')
        ->and($v->unit_cost)->toBe('4.5000')
        ->and($v->inventory_item_id)->toBe(100)
        ->and($v->shopify_created_at->toDateString())->toBe('2025-03-01');

    $v->update(['supplier_id' => $supplier->id, 'lead_time_override' => 21, 'safety_days' => 3]);

    app(VariantImporter::class)->import($this->shop, jsonlFile([variantLine(1, ['sku' => 'NEW', 'product' => ['status' => 'ARCHIVED']])]));

    $v->refresh();
    expect($v->sku)->toBe('NEW')
        ->and($v->is_active)->toBeFalse()
        ->and($v->supplier_id)->toBe($supplier->id)
        ->and($v->lead_time_override)->toBe(21)
        ->and($v->safety_days)->toBe(3);
});

it('imports native bundle components without touching manual ones', function () {
    $lines = [
        variantLine(1), variantLine(2), variantLine(3),
        variantLine(9, ['requiresComponents' => true]),
        ['quantity' => 2, 'productVariant' => ['id' => gid('ProductVariant', 1)], '__parentId' => gid('ProductVariant', 9)],
        ['quantity' => 1, 'productVariant' => ['id' => gid('ProductVariant', 2)], '__parentId' => gid('ProductVariant', 9)],
    ];
    app(VariantImporter::class)->import($this->shop, jsonlFile($lines));

    $bundle = Variant::forShop($this->shop)->where('shopify_variant_id', 9)->first();
    expect($bundle->is_bundle)->toBeTrue()
        ->and($bundle->bundleComponents()->where('source', 'shopify')->pluck('quantity')->sort()->values()->all())->toBe([1, 2]);

    // Merchant adds a manual component; Shopify later drops component 2.
    $three = Variant::forShop($this->shop)->where('shopify_variant_id', 3)->first();
    BundleComponent::create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $bundle->id, 'component_variant_id' => $three->id, 'quantity' => 1, 'source' => 'manual']);

    app(VariantImporter::class)->import($this->shop, jsonlFile([
        variantLine(9, ['requiresComponents' => true]),
        ['quantity' => 2, 'productVariant' => ['id' => gid('ProductVariant', 1)], '__parentId' => gid('ProductVariant', 9)],
    ]));

    expect($bundle->bundleComponents()->count())->toBe(2)
        ->and($bundle->bundleComponents()->where('source', 'manual')->count())->toBe(1);
});

it('imports inventory, drops stale levels and deactivates deleted variants', function () {
    app(VariantImporter::class)->import($this->shop, jsonlFile([variantLine(1), variantLine(2)]));
    $main = Location::factory()->for($this->shop)->create(['shopify_location_id' => 11]);
    $old = Location::factory()->for($this->shop)->create(['shopify_location_id' => 12]);
    Location::factory()->for($this->shop)->create(['shopify_location_id' => 13, 'is_active' => false]);

    $v1 = Variant::forShop($this->shop)->where('shopify_variant_id', 1)->first();
    $v2 = Variant::forShop($this->shop)->where('shopify_variant_id', 2)->first();
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $v1->id, 'location_id' => $old->id, 'available' => 9]);
    InventoryLevel::query()->update(['updated_at' => now()->subDay()]);

    $level = fn (int $item, int $location, int $qty) => [
        'location' => ['id' => gid('Location', $location)],
        'quantities' => [['name' => 'available', 'quantity' => $qty]],
        '__parentId' => gid('InventoryItem', $item),
    ];
    // Variant 2's inventory item is gone (variant deleted in Shopify).
    $stats = app(InventoryImporter::class)->import($this->shop, jsonlFile([
        $level(100, 11, 7), // child before parent
        ['id' => gid('InventoryItem', 100), 'tracked' => true, 'variant' => ['id' => gid('ProductVariant', 1)]],
        $level(100, 13, 50), // inactive location: ignored
    ]), Carbon::now());

    expect(InventoryLevel::where('variant_id', $v1->id)->pluck('available', 'location_id')->all())->toBe([$main->id => 7])
        ->and($v2->fresh()->is_active)->toBeFalse()
        ->and($v1->fresh()->is_active)->toBeTrue()
        ->and($stats)->toBe(['levels' => 1, 'deactivated' => 1]);
});

it('imports stock on the way (Shopify incoming)', function () {
    app(VariantImporter::class)->import($this->shop, jsonlFile([variantLine(1)]));
    $main = Location::factory()->for($this->shop)->create(['shopify_location_id' => 11]);
    $v1 = Variant::forShop($this->shop)->where('shopify_variant_id', 1)->first();

    app(InventoryImporter::class)->import($this->shop, jsonlFile([
        ['id' => gid('InventoryItem', 100), 'tracked' => true, 'variant' => ['id' => gid('ProductVariant', 1)]],
        [
            'location' => ['id' => gid('Location', 11)],
            'quantities' => [['name' => 'available', 'quantity' => 7], ['name' => 'incoming', 'quantity' => 40]],
            '__parentId' => gid('InventoryItem', 100),
        ],
    ]), Carbon::now());

    expect(InventoryLevel::where('variant_id', $v1->id)->first()->only(['location_id', 'available', 'incoming']))
        ->toBe(['location_id' => $main->id, 'available' => 7, 'incoming' => 40])
        ->and(app(CatalogRepositoryInterface::class)->incomingByVariant($this->shop))->toBe([$v1->id => 40]);
});
