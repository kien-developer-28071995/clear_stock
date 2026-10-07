<?php

namespace App\Services\App;

use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\CostRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Import\PurchaseOrderCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reorder settings of many products from a CSV: one row per product, found by SKU (or barcode).
 * An empty cell leaves that setting as it is. Nothing is written until the merchant has seen
 * what the file would change ($apply). A row with a value that cannot be used is skipped whole.
 */
class ProductSettingsImport
{
    private const MAX_ROWS = 5000;

    private const SKU = ['sku', 'variantsku', 'itemsku'];

    private const BARCODE = ['barcode', 'variantbarcode', 'upc', 'ean', 'gtin'];

    /** Setting => header names (lowercase, letters and digits only) and the whole numbers it accepts. */
    private const NUMBERS = [
        'lead_time_override' => [['leadtime', 'leadtimedays', 'leadtimeoverride', 'leaddays'], 0, 365],
        'safety_days' => [['safetydays', 'safetystockdays', 'safety'], 0, 365],
        'min_order_qty' => [['minorderqty', 'minimumorderquantity', 'minorderquantity', 'minorder', 'moq'], 1, 1_000_000],
        'pack_size' => [['packsize', 'casesize', 'caseqty', 'unitspercase', 'casepack'], 1, 100_000],
        'min_stock' => [['minstock', 'min', 'reorderpoint', 'minimumstock'], 0, 1_000_000],
        'max_stock' => [['maxstock', 'max', 'maximumstock', 'orderupto'], 1, 1_000_000],
    ];

    private const SUPPLIER = ['supplier', 'suppliername', 'vendor'];

    private const DISCONTINUED = ['discontinued', 'stopordering', 'stopreordering'];

    private const YES = ['yes', 'y', 'true', '1', 'x'];

    private const NO = ['no', 'n', 'false', '0'];

    public function __construct(
        private readonly PurchaseOrderCsv $csv,
        private readonly CostRepositoryInterface $costs,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly VariantRepositoryInterface $variants,
        private readonly ForecastAdjustmentService $adjust,
    ) {}

    /** @return array<string, mixed> what the file changes (or changed, with $apply) */
    public function run(Shop $shop, UploadedFile $file, bool $apply): array
    {
        ['columns' => $columns, 'rows' => $rows] = $this->csv->read([$file]);
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'too_many_rows']);
        }
        $find = function (array $aliases) use ($columns): ?string {
            foreach ($columns as $c) {
                if (in_array(Str::of($c)->lower()->ascii()->replaceMatches('/[^a-z0-9]/', '')->toString(), $aliases, true)) {
                    return $c;
                }
            }

            return null;
        };
        $skuCol = $find(self::SKU);
        $barcodeCol = $find(self::BARCODE);
        $numberCols = array_filter(array_map(fn ($def) => $find($def[0]), self::NUMBERS));
        $supplierCol = $find(self::SUPPLIER);
        $discontinuedCol = $find(self::DISCONTINUED);
        $fields = [...array_keys($numberCols), ...($supplierCol ? ['supplier_id'] : []), ...($discontinuedCol ? ['discontinued'] : [])];
        if (($skuCol === null && $barcodeCol === null) || $fields === []) {
            throw ValidationException::withMessages(['file' => 'settings_columns_missing']);
        }

        $ids = $this->costs->idsBySkuAndBarcode($shop);
        $supplierIds = $this->suppliers->allForShop($shop)->mapWithKeys(fn (Supplier $s) => [mb_strtolower(trim($s->name)) => $s->id])->all();
        $changes = [];
        $unmatched = [];
        $invalid = [];
        $unknownSuppliers = [];

        foreach ($rows as $i => $row) {
            $key = trim((string) ($skuCol ? $row[$skuCol] ?? '' : ''));
            $id = $ids[mb_strtolower($key)] ?? null;
            if ($id === null && $barcodeCol !== null) {
                $barcode = trim((string) ($row[$barcodeCol] ?? ''));
                $id = $ids[mb_strtolower($barcode)] ?? null;
                $key = $key !== '' ? $key : $barcode;
            }
            $settings = [];
            $bad = null;
            foreach ($numberCols as $field => $col) {
                $raw = trim((string) ($row[$col] ?? ''));
                if ($raw === '') {
                    continue;
                }
                [, $min, $max] = self::NUMBERS[$field];
                if (! ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
                    $bad ??= $field;

                    continue;
                }
                $settings[$field] = (int) $raw;
            }
            if ($supplierCol !== null && ($name = trim((string) ($row[$supplierCol] ?? ''))) !== '') {
                if (isset($supplierIds[mb_strtolower($name)])) {
                    $settings['supplier_id'] = $supplierIds[mb_strtolower($name)];
                } else {
                    $unknownSuppliers[] = $name;
                    $bad ??= 'supplier_id';
                }
            }
            if ($discontinuedCol !== null && ($flag = mb_strtolower(trim((string) ($row[$discontinuedCol] ?? '')))) !== '') {
                if (in_array($flag, self::YES, true) || in_array($flag, self::NO, true)) {
                    $settings['discontinued'] = in_array($flag, self::YES, true);
                } else {
                    $bad ??= 'discontinued';
                }
            }
            if ($settings === [] && $bad === null) {
                continue; // nothing in this row
            }
            if ($id === null) {
                if ($key !== '') {
                    $unmatched[] = $key;
                }

                continue;
            }
            if ($bad !== null) {
                $invalid[] = ['row' => $i + 2, 'sku' => $key, 'field' => $bad];

                continue;
            }
            // The last row for a product wins, setting by setting.
            $changes[$id] = $settings + ($changes[$id] ?? []);
        }

        // A maximum under the minimum: checked against what the product keeps.
        $kept = $this->variants->findMany($shop, array_keys($changes));
        foreach ($changes as $id => $settings) {
            $min = array_key_exists('min_stock', $settings) ? $settings['min_stock'] : $kept[$id]?->min_stock;
            $max = array_key_exists('max_stock', $settings) ? $settings['max_stock'] : $kept[$id]?->max_stock;
            if ($min !== null && $max !== null && $max < $min) {
                $invalid[] = ['row' => null, 'sku' => (string) $kept[$id]?->sku, 'field' => 'max_stock'];
                unset($changes[$id]);
            }
        }

        if ($apply && $changes !== []) {
            $this->adjust->applyVariantSettings($shop, $changes);
        }

        return [
            'applied' => $apply,
            'products' => count($changes),
            'fields' => $fields,
            'unmatched' => count($unmatched),
            'unmatched_examples' => array_slice(array_values(array_unique($unmatched)), 0, 10),
            'invalid' => count($invalid),
            'invalid_examples' => array_slice($invalid, 0, 10),
            'unknown_suppliers' => array_slice(array_values(array_unique($unknownSuppliers)), 0, 10),
        ];
    }
}
