<?php

namespace App\Http\Requests;

use App\Support\ShopContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VariantSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        $shopId = app(ShopContext::class)->shop()->id;

        return [
            'variant_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            // Local ids or Shopify variant gids (App Bridge resource picker).
            'variant_ids.*' => ['required', function (string $attr, mixed $value, \Closure $fail) {
                if (! is_int($value) && ! (is_string($value) && preg_match('#^gid://shopify/ProductVariant/\d+$#', $value))) {
                    $fail('invalid_product');
                }
            }],
            'supplier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('suppliers', 'id')->where('shop_id', $shopId)],
            'lead_time_override' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'safety_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'min_order_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'pack_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'min_stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'max_stock' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'alerts_muted' => ['sometimes', 'boolean'],
            // Unit cost entered in the app (null = Shopify's cost).
            'cost_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000000'],
            // No longer reordered: sells through what is left (no suggestions, alerts, plan).
            'discontinued' => ['sometimes', 'boolean'],
            // New products: a similar product (local id or Shopify variant gid) and the share of its rate.
            'reference_variant' => ['sometimes', 'nullable', function (string $attr, mixed $value, \Closure $fail) {
                if (! is_int($value) && ! (is_string($value) && preg_match('#^gid://shopify/ProductVariant/\d+$#', $value))) {
                    $fail('invalid_product');
                }
            }],
            'reference_percent' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:500'],
        ];
    }

    /** @return array{cost_override?: ?float} */
    public function costSetting(): array
    {
        return $this->safe()->only(['cost_override']);
    }

    /** Reference product settings (single product only). @return array{reference_variant?: int|string|null, reference_percent?: ?int} */
    public function referenceSettings(): array
    {
        return $this->safe()->only(['reference_variant', 'reference_percent']);
    }

    public function settings(): array
    {
        return $this->safe()->only(['supplier_id', 'lead_time_override', 'safety_days', 'min_order_qty', 'pack_size', 'min_stock', 'max_stock', 'alerts_muted', 'discontinued']);
    }
}
