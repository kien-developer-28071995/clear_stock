<?php

namespace App\Services\App;

use App\Models\ChangeLog;
use App\Models\Shop;
use App\Support\Features;
use App\Support\Monitor;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a merchant changed on a product, old value next to new (every plan): the answer to
 * "why did this number move?" when it was a setting and not the sales. Only real changes are
 * kept. Writing the log never fails the change itself.
 */
class ChangeLogService
{
    public const SWITCH = 'change_log';

    /** Product settings that are logged under their own name. */
    private const SETTINGS = [
        'lead_time_override', 'safety_days', 'min_order_qty', 'pack_size', 'min_stock', 'max_stock',
        'alerts_muted', 'discontinued', 'forecast_profile', 'supplier_sku', 'reference_percent', 'cost_override', 'snoozed_until',
    ];

    private const PAGE = 50;

    /**
     * @param  array<string, mixed>  $before  values before the change (settings keys, `override.<field>`)
     * @param  array<string, mixed>  $after  the same keys after it; keys missing here did not change
     */
    public function record(Shop $shop, int $variantId, array $before, array $after, string $source = 'app'): void
    {
        $this->recordMany($shop, [$variantId => $before], $after, $source);
    }

    /**
     * The same new values applied to many products (bulk edit).
     *
     * @param  array<int, array<string, mixed>>  $beforeByVariant
     * @param  array<string, mixed>  $after
     */
    public function recordMany(Shop $shop, array $beforeByVariant, array $after, string $source = 'bulk'): void
    {
        if (! Features::on(self::SWITCH) || $after === [] || $beforeByVariant === []) {
            return;
        }
        try {
            $names = $this->names($shop, $beforeByVariant, $after);
            $now = now();
            $rows = [];
            foreach ($beforeByVariant as $variantId => $before) {
                foreach ($after as $key => $new) {
                    $field = $this->field($key);
                    if ($field === null) {
                        continue;
                    }
                    $old = $this->text($key, $before[$key] ?? null, $names);
                    $new = $this->text($key, $new, $names);
                    if ($old !== $new) {
                        $rows[] = ['shop_id' => $shop->id, 'variant_id' => (int) $variantId, 'field' => $field, 'old_value' => $old, 'new_value' => $new, 'source' => $source, 'created_at' => $now];
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                ChangeLog::query()->insert($chunk);
            }
        } catch (Throwable $e) {
            Monitor::caught($e, 'ChangeLogService::recordMany');
        }
    }

    /** @return array<int, array{id: int, field: string, old: ?string, new: ?string, source: string, at: string}> newest first */
    public function list(Shop $shop, int $variantId): array
    {
        return ChangeLog::query()->where('shop_id', $shop->id)->where('variant_id', $variantId)
            ->orderByDesc('id')->limit(self::PAGE)->get()
            ->map(fn (ChangeLog $c) => [
                'id' => $c->id,
                'field' => $c->field,
                'old' => $c->old_value,
                'new' => $c->new_value,
                'source' => $c->source,
                'at' => $c->created_at->toIso8601String(),
            ])->all();
    }

    private function field(string $key): ?string
    {
        return match (true) {
            $key === 'supplier_id' => 'supplier',
            $key === 'reference_variant_id' => 'reference',
            in_array($key, self::SETTINGS, true), str_starts_with($key, 'override.') => $key,
            default => null,
        };
    }

    /** Supplier and product names by id, for every id on either side of the change. */
    private function names(Shop $shop, array $beforeByVariant, array $after): array
    {
        $ids = fn (string $key) => array_values(array_unique(array_filter(array_map('intval', [
            ...array_column($beforeByVariant, $key), $after[$key] ?? 0,
        ]))));
        $suppliers = array_key_exists('supplier_id', $after) && $ids('supplier_id') !== []
            ? DB::table('suppliers')->where('shop_id', $shop->id)->whereIn('id', $ids('supplier_id'))->pluck('name', 'id')->all() : [];
        $products = array_key_exists('reference_variant_id', $after) && $ids('reference_variant_id') !== []
            ? DB::table('variants')->where('shop_id', $shop->id)->whereIn('id', $ids('reference_variant_id'))->get(['id', 'product_title', 'title'])
                ->mapWithKeys(fn ($v) => [(int) $v->id => $v->title && $v->title !== 'Default Title' ? "{$v->product_title} - {$v->title}" : $v->product_title])->all() : [];

        return ['supplier_id' => $suppliers, 'reference_variant_id' => $products];
    }

    private function text(string $key, mixed $value, array $names): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (isset($names[$key])) {
            $value = $names[$key][(int) $value] ?? '#'.(int) $value;
        } elseif (is_bool($value) || in_array($key, ['alerts_muted', 'discontinued'], true)) {
            $value = $value ? '1' : '0';
        } elseif ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        } elseif (is_numeric($value) && ! is_string($value) || (is_string($value) && is_numeric($value) && $key !== 'supplier_sku')) {
            // 14, 14.0 and "14.0000" are the same value.
            $value = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return mb_substr((string) $value, 0, 191);
    }
}
