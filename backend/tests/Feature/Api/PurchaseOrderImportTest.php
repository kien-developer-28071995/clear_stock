<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

// Stocky can export purchase orders but not suppliers: the importer rebuilds suppliers,
// who supplies what, and lead times from purchase order CSVs.

beforeEach(function () {
    Queue::fake();
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'plan' => 'free']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->mug = Variant::factory()->for($this->shop)->create(['sku' => 'MUG-1', 'product_title' => 'Mug', 'title' => 'Default Title', 'shopify_variant_id' => 111]);
    $this->plate = Variant::factory()->for($this->shop)->create(['sku' => 'PLATE-1', 'product_title' => 'Plate', 'title' => 'Default Title', 'shopify_variant_id' => 222]);
    $this->glass = Variant::factory()->for($this->shop)->create(['sku' => null, 'product_title' => 'Glass', 'title' => 'Blue', 'shopify_variant_id' => 333]);
});

function stockyCsv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('purchase-orders.csv', "\xEF\xBB\xBF".implode("\n", [
        'Purchase Order ID,Supplier,Status,Ordered At,Expected On,Received At,SKU,Product,Variant,Quantity,Received,Cost',
        'PO-1,Acme Ceramics,received,2026-06-01,2026-06-10,2026-06-15,MUG-1,Mug,,50,50,3.50',
        'PO-1,Acme Ceramics,received,2026-06-01,2026-06-10,2026-06-15,PLATE-1,Plate,,20,20,5.00',
        'PO-2,Acme Ceramics,received,2026-07-01,,2026-07-11,MUG-1,Mug,,40,40,3.50',
        'PO-3,Glass Co,ordered,2026-08-01,2026-08-20,,,Glass,Blue,10,0,2.00',  // not received: expected date counts
        'PO-4,Glass Co,received,2026-09-01,,2026-09-05,MUG-1,Mug,,5,5,3.60', // mug last ordered here
        'PO-4,Glass Co,received,2026-09-01,,2026-09-05,NOPE-9,Unknown thing,,5,5,1.00',
    ]));
}

it('previews suppliers, lead times and matched products without saving anything', function () {
    $this->post('/api/imports/purchase-orders/preview', ['files' => [stockyCsv()]], $this->auth)
        ->assertOk()
        ->assertJsonPath('data.missing', [])
        ->assertJsonPath('data.mapping', [
            'po_number' => 'Purchase Order ID', 'supplier' => 'Supplier', 'sku' => 'SKU', 'variant_id' => null,
            'product' => 'Product', 'variant' => 'Variant', 'ordered_at' => 'Ordered At', 'expected_at' => 'Expected On', 'received_at' => 'Received At',
        ])
        ->assertJsonPath('data.rows', 6)
        ->assertJsonPath('data.purchase_orders', 4)
        // Acme: 14 and 10 days -> 12. Glass Co: 19 (expected) and 4 -> 11.5 -> 12.
        ->assertJsonPath('data.suppliers', [
            ['name' => 'Acme Ceramics', 'existing' => false, 'current_lead_time_days' => null, 'products' => 2, 'purchase_orders' => 2, 'lead_time_days' => 12, 'lead_time_samples' => 2],
            ['name' => 'Glass Co', 'existing' => false, 'current_lead_time_days' => null, 'products' => 2, 'purchase_orders' => 2, 'lead_time_days' => 12, 'lead_time_samples' => 2],
        ])
        ->assertJsonPath('data.products', ['matched' => 3, 'unmatched' => 1, 'unmatched_samples' => ['NOPE-9'], 'with_supplier' => 0]);

    expect(Supplier::count())->toBe(0);
});

it('creates suppliers and assigns each product to the supplier it was last ordered from', function () {
    $this->post('/api/imports/purchase-orders/apply', [
        'files' => [stockyCsv()],
        'suppliers' => json_encode([['name' => 'Glass Co', 'lead_time_days' => 21]]), // merchant corrected it
    ], $this->auth)
        ->assertOk()
        ->assertJsonPath('data', ['suppliers_created' => 2, 'suppliers_updated' => 0, 'products_assigned' => 3, 'products_kept' => 0]);

    $acme = Supplier::where('name', 'Acme Ceramics')->first();
    $glassCo = Supplier::where('name', 'Glass Co')->first();
    expect($acme->lead_time_days)->toBe(12)
        ->and($glassCo->lead_time_days)->toBe(21)
        ->and($this->mug->fresh()->supplier_id)->toBe($glassCo->id)
        ->and($this->plate->fresh()->supplier_id)->toBe($acme->id)
        ->and($this->glass->fresh()->supplier_id)->toBe($glassCo->id);
    Queue::assertPushed(RecomputeForecasts::class);
});

it('reuses existing suppliers and keeps existing assignments unless asked to replace them', function () {
    $acme = Supplier::factory()->for($this->shop)->create(['name' => 'ACME ceramics', 'lead_time_days' => 30]);
    $other = Supplier::factory()->for($this->shop)->create(['name' => 'Other']);
    $this->plate->update(['supplier_id' => $other->id]);

    $this->post('/api/imports/purchase-orders/preview', ['files' => [stockyCsv()]], $this->auth)
        ->assertJsonPath('data.suppliers.0.existing', true)
        ->assertJsonPath('data.suppliers.0.current_lead_time_days', 30)
        ->assertJsonPath('data.products.with_supplier', 1);

    $this->post('/api/imports/purchase-orders/apply', ['files' => [stockyCsv()]], $this->auth)
        ->assertJsonPath('data.suppliers_created', 1)
        ->assertJsonPath('data.products_kept', 1);
    expect($this->plate->fresh()->supplier_id)->toBe($other->id)
        ->and($acme->fresh()->lead_time_days)->toBe(30) // not confirmed in the app: unchanged
        ->and(Supplier::count())->toBe(3);

    $this->post('/api/imports/purchase-orders/apply', ['files' => [stockyCsv()], 'replace_existing' => '1'], $this->auth)
        ->assertJsonPath('data.suppliers_created', 0);
    expect($this->plate->fresh()->supplier_id)->toBe($acme->id);
});

it('lets the merchant map unfamiliar columns, and reads semicolons and Shopify ids', function () {
    $file = fn () => UploadedFile::fake()->createWithContent('export.csv', implode("\n", [
        'Lieferant;Artikel;Bestellt',
        'Acme;gid://shopify/ProductVariant/222;2026-05-01',
        'Acme;111;2026-05-02',
    ]));

    $this->post('/api/imports/purchase-orders/preview', ['files' => [$file()]], $this->auth)
        ->assertOk()
        ->assertJsonPath('data.columns', ['Lieferant', 'Artikel', 'Bestellt'])
        ->assertJsonPath('data.missing', ['supplier', 'product']);

    $mapping = json_encode(['supplier' => 'Lieferant', 'variant_id' => 'Artikel', 'ordered_at' => 'Bestellt', 'sku' => 'Not a column']);
    $this->post('/api/imports/purchase-orders/preview', ['files' => [$file()], 'mapping' => $mapping], $this->auth)
        ->assertJsonPath('data.missing', [])
        ->assertJsonPath('data.mapping.sku', null)
        ->assertJsonPath('data.products.matched', 2)
        ->assertJsonPath('data.suppliers.0.lead_time_days', null); // no delivery dates: store default applies
});

it('rejects files it cannot use, with codes', function () {
    $this->post('/api/imports/purchase-orders/preview', ['files' => [UploadedFile::fake()->createWithContent('e.csv', "Supplier,SKU\n")]], $this->auth)
        ->assertUnprocessable()->assertJsonPath('errors.files.0.code', 'no_rows');

    $this->post('/api/imports/purchase-orders/apply', ['files' => [UploadedFile::fake()->createWithContent('e.csv', "Foo,Bar\n1,2\n")]], $this->auth)
        ->assertUnprocessable()->assertJsonPath('errors.mapping.0.code', 'mapping_incomplete');

    $this->post('/api/imports/purchase-orders/preview', [], $this->auth)
        ->assertUnprocessable()->assertJsonPath('errors.files.0.code', 'required');
});
