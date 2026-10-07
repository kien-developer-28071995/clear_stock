<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\ChangeLog;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'free', 'access_token_expires_at' => now()->addYear()]);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme']);
    $this->mug = Variant::factory()->for($this->shop)->create(['sku' => 'MUG-1', 'lead_time_override' => 10, 'min_stock' => 5]);
    $this->cup = Variant::factory()->for($this->shop)->create(['sku' => 'CUP-1', 'barcode' => '4006381333931']);
    $this->vase = Variant::factory()->for($this->shop)->create(['sku' => 'VASE-1', 'min_stock' => 50]);
});

function settingsCsv(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('settings.csv', $content);
}

const SETTINGS_FILE = <<<'CSV'
    SKU,Barcode,Lead time (days),MOQ,Pack size,Min stock,Max stock,Supplier,Discontinued
    MUG-1,,21,24,12,,,acme,
    ,4006381333931,,,,,,,yes
    VASE-1,,,,,,20,,
    GONE-9,,7,,,,,,
    CUP-1,,soon,,,,,,
    MUG-1,,,,,,,Nobody Ltd,
    CSV;

it('shows what a file would change before anything is written', function () {
    $file = preg_replace('/^    /m', '', SETTINGS_FILE);

    $this->post('/api/variants/settings/import', ['file' => settingsCsv($file)], $this->auth)->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.products', 2)
        ->assertJsonPath('data.fields', ['lead_time_override', 'min_order_qty', 'pack_size', 'min_stock', 'max_stock', 'supplier_id', 'discontinued'])
        ->assertJsonPath('data.unmatched', 1)->assertJsonPath('data.unmatched_examples', ['GONE-9'])
        // A lead time that is no number, a supplier that does not exist, a maximum under the product's minimum.
        ->assertJsonPath('data.invalid', 3)
        ->assertJsonPath('data.invalid_examples.0', ['row' => 6, 'sku' => 'CUP-1', 'field' => 'lead_time_override'])
        ->assertJsonPath('data.invalid_examples.1', ['row' => 7, 'sku' => 'MUG-1', 'field' => 'supplier_id'])
        ->assertJsonPath('data.invalid_examples.2', ['row' => null, 'sku' => 'VASE-1', 'field' => 'max_stock'])
        ->assertJsonPath('data.unknown_suppliers', ['Nobody Ltd']);

    expect($this->mug->fresh()->lead_time_override)->toBe(10)->and(ChangeLog::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('applies the rows it can use, leaves empty cells alone and records the changes', function () {
    $file = preg_replace('/^    /m', '', SETTINGS_FILE);

    $this->post('/api/variants/settings/import', ['file' => settingsCsv($file), 'apply' => '1'], $this->auth)->assertOk()
        ->assertJsonPath('data.applied', true)->assertJsonPath('data.products', 2);

    $mug = $this->mug->fresh();
    expect($mug->only(['lead_time_override', 'min_order_qty', 'pack_size', 'min_stock', 'supplier_id']))
        ->toBe(['lead_time_override' => 21, 'min_order_qty' => 24, 'pack_size' => 12, 'min_stock' => 5, 'supplier_id' => $this->acme->id])
        // Found by its barcode.
        ->and($this->cup->fresh()->discontinued)->toBeTrue()
        ->and($this->vase->fresh()->max_stock)->toBeNull()
        ->and(ChangeLog::where('variant_id', $mug->id)->count())->toBeGreaterThan(0);
    Queue::assertPushed(RecomputeForecasts::class);
});

it('refuses a file without a product column or without any setting', function () {
    $this->post('/api/variants/settings/import', ['file' => settingsCsv("SKU,Title\nMUG-1,Mug\n")], $this->auth + ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('errors.file.0.code', 'settings_columns_missing');
});

it('never touches another shop\'s products and does not exist when switched off', function () {
    $other = Shop::factory()->create();
    $theirs = Variant::factory()->for($other)->create(['sku' => 'THEIRS-1', 'lead_time_override' => 3]);
    $this->post('/api/variants/settings/import', ['file' => settingsCsv("SKU,Lead time\nTHEIRS-1,30\n"), 'apply' => '1'], $this->auth)
        ->assertOk()->assertJsonPath('data.products', 0)->assertJsonPath('data.unmatched', 1);
    expect($theirs->fresh()->lead_time_override)->toBe(3);

    config(['features.settings_import' => false]);
    $this->post('/api/variants/settings/import', ['file' => settingsCsv("SKU,Lead time\nMUG-1,30\n")], $this->auth + ['Accept' => 'application/json'])->assertNotFound();
});
