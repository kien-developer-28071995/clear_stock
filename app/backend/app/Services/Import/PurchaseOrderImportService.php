<?php

namespace App\Services\Import;

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\Gid;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rebuilds suppliers from purchase order CSVs (Stocky can't export its suppliers,
 * only purchase orders): one supplier per supplier name, each product assigned to
 * the supplier it was last ordered from, and a lead time measured from past
 * deliveries (median days from ordered to received, or to expected).
 *
 * Stateless: the app sends the files to preview, then again to apply.
 */
class PurchaseOrderImportService
{
    private const UNMATCHED_SAMPLES = 10;

    public function __construct(
        private readonly PurchaseOrderCsv $csv,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly VariantRepositoryInterface $variants,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<string, ?string>|null  $mapping  field => column; null = detect
     */
    public function preview(Shop $shop, array $files, ?array $mapping): array
    {
        ['columns' => $columns, 'rows' => $rows] = $this->csv->read($files);
        $mapping = $this->mapping($columns, $rows, $mapping);
        $missing = $this->missing($mapping);

        $base = ['columns' => $columns, 'mapping' => $mapping, 'missing' => $missing, 'rows' => count($rows)];
        if ($missing !== []) {
            return $base + ['purchase_orders' => 0, 'suppliers' => [], 'products' => ['matched' => 0, 'unmatched' => 0, 'unmatched_samples' => [], 'with_supplier' => 0]];
        }

        $a = $this->analyse($shop, $rows, $mapping);

        return $base + [
            'purchase_orders' => $a['purchase_orders'],
            'suppliers' => array_values(array_map(fn ($s) => [
                'name' => $s['name'],
                'existing' => $s['existing'] !== null,
                'current_lead_time_days' => $s['existing']?->lead_time_days,
                'products' => count($s['variants']),
                'purchase_orders' => count($s['orders']),
                'lead_time_days' => $s['lead_time_days'],
                'lead_time_samples' => count($s['samples']),
            ], $a['suppliers'])),
            'products' => [
                'matched' => count($a['assignments']),
                'unmatched' => count($a['unmatched']),
                'unmatched_samples' => array_slice(array_keys($a['unmatched']), 0, self::UNMATCHED_SAMPLES),
                // Already linked to a supplier in the app: kept unless the merchant chooses to replace.
                'with_supplier' => count(array_filter($a['assignments'], fn ($x) => $x['current_supplier_id'] !== null)),
            ],
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<string, ?string>|null  $mapping
     * @param  array<int, array{name: string, lead_time_days: ?int}>  $leadTimes  lead times confirmed in the app, by supplier name
     * @return array{suppliers_created: int, suppliers_updated: int, products_assigned: int, products_kept: int}
     */
    public function apply(Shop $shop, array $files, ?array $mapping, array $leadTimes, bool $replaceExisting): array
    {
        ['columns' => $columns, 'rows' => $rows] = $this->csv->read($files);
        $mapping = $this->mapping($columns, $rows, $mapping);
        if ($this->missing($mapping) !== []) {
            throw ValidationException::withMessages(['mapping' => 'mapping_incomplete']);
        }

        $a = $this->analyse($shop, $rows, $mapping);
        $confirmed = [];
        foreach ($leadTimes as $l) {
            $confirmed[mb_strtolower($l['name'])] = $l['lead_time_days'];
        }

        $result = ['suppliers_created' => 0, 'suppliers_updated' => 0, 'products_assigned' => 0, 'products_kept' => 0];
        DB::transaction(function () use ($shop, $a, $confirmed, $replaceExisting, &$result) {
            $ids = [];
            foreach ($a['suppliers'] as $key => $s) {
                $lead = array_key_exists($key, $confirmed) ? $confirmed[$key] : $s['lead_time_days'];
                if ($s['existing'] === null) {
                    $ids[$key] = $this->suppliers->create($shop, ['name' => $s['name'], 'lead_time_days' => $lead])->id;
                    $result['suppliers_created']++;
                } else {
                    $ids[$key] = $s['existing']->id;
                    if (array_key_exists($key, $confirmed) && $lead !== $s['existing']->lead_time_days) {
                        $this->suppliers->update($s['existing'], ['lead_time_days' => $lead]);
                        $result['suppliers_updated']++;
                    }
                }
            }

            $bySupplier = [];
            foreach ($a['assignments'] as $variantId => $x) {
                if ($x['current_supplier_id'] !== null && ! $replaceExisting) {
                    $result['products_kept']++;

                    continue;
                }
                $bySupplier[$ids[$x['supplier']]][] = $variantId;
            }
            foreach ($bySupplier as $supplierId => $variantIds) {
                $result['products_assigned'] += $this->variants->bulkUpdateSettings($shop, $variantIds, ['supplier_id' => $supplierId]);
            }
        });

        RecomputeForecasts::dispatch($shop->id);

        return $result;
    }

    /** @return array<int, string> required fields without a column */
    private function missing(array $mapping): array
    {
        $missing = $mapping['supplier'] === null ? ['supplier'] : [];
        if ($mapping['sku'] === null && $mapping['variant_id'] === null && $mapping['product'] === null) {
            $missing[] = 'product';
        }

        return $missing;
    }

    /** The merchant's mapping (unknown columns ignored) or the detected one. */
    private function mapping(array $columns, array $rows, ?array $given): array
    {
        if ($given === null) {
            return $this->csv->detect($columns, $rows);
        }
        $mapping = array_fill_keys(PurchaseOrderCsv::FIELDS, null);
        foreach (PurchaseOrderCsv::FIELDS as $field) {
            $column = $given[$field] ?? null;
            $mapping[$field] = is_string($column) && in_array($column, $columns, true) ? $column : null;
        }

        return $mapping;
    }

    /**
     * @return array{
     *     purchase_orders: int,
     *     suppliers: array<string, array{name: string, existing: ?Supplier, variants: array<int, true>, orders: array<string, true>, samples: array<int, int>, lead_time_days: ?int}>,
     *     assignments: array<int, array{supplier: string, current_supplier_id: ?int}>,
     *     unmatched: array<string, true>,
     * }
     */
    private function analyse(Shop $shop, array $rows, array $mapping): array
    {
        $lookup = $this->lookup($this->variants->forImport($shop));
        $existing = $this->suppliers->allForShop($shop)->keyBy(fn (Supplier $s) => mb_strtolower($s->name));
        $col = fn (array $row, string $field) => $mapping[$field] !== null ? ($row[$mapping[$field]] ?? '') : '';

        $suppliers = [];
        $orders = [];     // order key => [supplier key, ordered, arrived]
        $latest = [];     // variant id => [sort key, supplier key]
        $unmatched = [];

        foreach ($rows as $i => $row) {
            $name = preg_replace('/\s+/', ' ', trim($col($row, 'supplier')));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $suppliers[$key] ??= ['name' => $name, 'existing' => $existing[$key] ?? null, 'variants' => [], 'orders' => [], 'samples' => []];

            $orderKey = $col($row, 'po_number') !== '' ? $key.'|'.$col($row, 'po_number') : "row{$i}";
            $ordered = $this->csv->date($col($row, 'ordered_at'));
            $arrived = $this->csv->date($col($row, 'received_at')) ?? $this->csv->date($col($row, 'expected_at'));
            $orders[$orderKey] ??= [$key, $ordered, $arrived];
            $suppliers[$key]['orders'][$orderKey] = true;

            $variant = $this->match($lookup, $col($row, 'variant_id'), $col($row, 'sku'), $col($row, 'product'), $col($row, 'variant'));
            if ($variant === null) {
                $ref = $col($row, 'sku') ?: trim($col($row, 'product').' '.$col($row, 'variant'));
                if ($ref !== '') {
                    $unmatched[$ref] = true;
                }

                continue;
            }
            $suppliers[$key]['variants'][$variant->id] = true;
            // The supplier a product was ordered from most recently wins (row order breaks ties).
            $sort = ($ordered ?? '0000-00-00').sprintf('|%08d', $i);
            if (! isset($latest[$variant->id]) || $sort >= $latest[$variant->id][0]) {
                $latest[$variant->id] = [$sort, $key, $variant->supplier_id];
            }
        }

        foreach ($orders as [$key, $ordered, $arrived]) {
            if ($ordered !== null && $arrived !== null) {
                $days = CarbonImmutable::parse($ordered)->diffInDays(CarbonImmutable::parse($arrived), false);
                if ($days >= 0 && $days <= 365) {
                    $suppliers[$key]['samples'][] = (int) $days;
                }
            }
        }
        foreach ($suppliers as &$s) {
            $s['lead_time_days'] = $this->median($s['samples']);
        }
        unset($s);

        return [
            'purchase_orders' => count($orders),
            'suppliers' => $suppliers,
            'assignments' => array_map(fn ($l) => ['supplier' => $l[1], 'current_supplier_id' => $l[2]], $latest),
            'unmatched' => $unmatched,
        ];
    }

    /** @return array{sku: array<string, ?Variant>, shopify: array<int, Variant>, name: array<string, ?Variant>} null = ambiguous */
    private function lookup(Collection $variants): array
    {
        $out = ['sku' => [], 'shopify' => [], 'name' => []];
        foreach ($variants as $v) {
            $out['shopify'][(int) $v->shopify_variant_id] = $v;
            if ($v->sku !== null && trim($v->sku) !== '') {
                $k = mb_strtolower(trim($v->sku));
                $out['sku'][$k] = array_key_exists($k, $out['sku']) ? null : $v;
            }
            foreach (array_unique([$v->displayName(), $v->product_title.' '.$v->title]) as $name) {
                $k = mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
                $out['name'][$k] = array_key_exists($k, $out['name']) && $out['name'][$k]?->id !== $v->id ? null : $v;
            }
        }

        return $out;
    }

    private function match(array $lookup, string $variantId, string $sku, string $product, string $variant): ?Variant
    {
        $id = Gid::id($variantId) ?? (ctype_digit($variantId) ? (int) $variantId : null);
        if ($id !== null && isset($lookup['shopify'][$id])) {
            return $lookup['shopify'][$id];
        }
        if ($sku !== '' && ($found = $lookup['sku'][mb_strtolower($sku)] ?? null)) {
            return $found;
        }
        foreach (array_filter([trim("{$product} - {$variant}", ' -'), trim("{$product} {$variant}"), $product]) as $name) {
            if ($found = $lookup['name'][mb_strtolower(preg_replace('/\s+/', ' ', $name))] ?? null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<int, int> $samples */
    private function median(array $samples): ?int
    {
        if ($samples === []) {
            return null;
        }
        sort($samples);
        $mid = intdiv(count($samples), 2);
        $median = count($samples) % 2 ? $samples[$mid] : ($samples[$mid - 1] + $samples[$mid]) / 2;

        return max(1, (int) round($median));
    }
}
