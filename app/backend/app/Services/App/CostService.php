<?php

namespace App\Services\App;

use App\Models\Shop;
use App\Repositories\Contracts\CostRepositoryInterface;
use App\Services\Import\PurchaseOrderCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unit costs entered in the app, for products whose Shopify cost is missing (or not the real
 * landed cost). They win over Shopify's cost in every money figure: stock value, cash tied up,
 * purchase orders and the purchase plan. Shopify itself is not changed.
 */
class CostService
{
    private const LIMIT = 500;

    /** Header names (lowercase, letters and digits only) read as SKU / barcode / cost in an import. */
    private const SKU = ['sku', 'variantsku', 'itemsku'];

    private const BARCODE = ['barcode', 'variantbarcode', 'upc', 'ean', 'gtin'];

    private const COST = ['cost', 'unitcost', 'costperitem', 'variantcost', 'costprice', 'purchaseprice', 'giavon'];

    public function __construct(
        private readonly CostRepositoryInterface $costs,
        private readonly PurchaseOrderCsv $csv,
    ) {}

    public function list(Shop $shop, bool $missingOnly, ?string $search): array
    {
        return [
            'counts' => $this->costs->counts($shop),
            'items' => $this->costs->list($shop, $missingOnly, $search, self::LIMIT)->map(fn ($v) => [
                'variant_id' => (int) $v->id,
                'name' => $v->title && $v->title !== 'Default Title' ? "{$v->product_title} - {$v->title}" : $v->product_title,
                'sku' => $v->sku,
                'shopify_cost' => $v->shopify_unit_cost !== null ? (float) $v->shopify_unit_cost : null,
                'app_cost' => $v->cost_override !== null ? (float) $v->cost_override : null,
                'cost' => $v->unit_cost !== null ? (float) $v->unit_cost : null,
            ])->all(),
            'limit' => self::LIMIT,
        ];
    }

    /** @param array<int, array{variant_id: int, cost: ?float}> $items */
    public function update(Shop $shop, array $items): int
    {
        $costs = [];
        foreach ($items as $i) {
            $costs[(int) $i['variant_id']] = $i['cost'] === null ? null : round((float) $i['cost'], 4);
        }

        return $this->costs->setOverrides($shop, $costs);
    }

    /**
     * A CSV with a SKU or barcode column and a cost column (a Shopify product export works:
     * "Variant SKU", "Variant Barcode", "Cost per item"). Rows are matched by SKU, else barcode.
     *
     * @return array{updated: int, unmatched: int, invalid: int, unmatched_examples: array<int, string>}
     */
    public function import(Shop $shop, UploadedFile $file): array
    {
        ['columns' => $columns, 'rows' => $rows] = $this->csv->read([$file]);
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
        $costCol = $find(self::COST);
        if ($costCol === null || ($skuCol === null && $barcodeCol === null)) {
            throw ValidationException::withMessages(['file' => 'cost_columns_missing']);
        }

        $ids = $this->costs->idsBySkuAndBarcode($shop);
        $costs = [];
        $unmatched = [];
        $invalid = 0;
        foreach ($rows as $row) {
            $cost = $this->number($row[$costCol] ?? '');
            $key = trim((string) ($skuCol ? $row[$skuCol] ?? '' : ''));
            $id = $ids[mb_strtolower($key)] ?? null;
            if ($id === null && $barcodeCol !== null) {
                $id = $ids[mb_strtolower(trim((string) ($row[$barcodeCol] ?? '')))] ?? null;
                $key = $key !== '' ? $key : trim((string) ($row[$barcodeCol] ?? ''));
            }
            if ($cost === null) {
                $invalid += ($row[$costCol] ?? '') !== '' ? 1 : 0; // empty cost = nothing to set

                continue;
            }
            if ($id === null) {
                if ($key !== '') {
                    $unmatched[] = $key;
                }

                continue;
            }
            $costs[$id] = $cost;
        }

        return [
            'updated' => $costs === [] ? 0 : $this->costs->setOverrides($shop, $costs),
            'unmatched' => count($unmatched),
            'invalid' => $invalid,
            'unmatched_examples' => array_slice(array_values(array_unique($unmatched)), 0, 10),
        ];
    }

    /** "12.50", "12,50", "$1,234.5", "1.234,5" -> float; null when not a cost. */
    private function number(string $raw): ?float
    {
        $s = preg_replace('/[^\d.,-]/', '', $raw) ?? '';
        if ($s === '' || str_starts_with($s, '-')) {
            return null;
        }
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot) && strlen($s) - $lastComma - 1 !== 3) {
            $s = str_replace(['.', ','], ['', '.'], $s);   // decimal comma
        } else {
            $s = str_replace(',', '', $s);                  // thousands comma
        }

        return is_numeric($s) && (float) $s <= 10_000_000 ? round((float) $s, 4) : null;
    }
}
