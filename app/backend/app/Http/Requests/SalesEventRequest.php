<?php

namespace App\Http\Requests;

use App\Models\SalesEvent;
use App\Support\ShopContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesEventRequest extends FormRequest
{
    public function rules(): array
    {
        $shopId = app(ShopContext::class)->shop()->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            // At most ~3 months: longer changes are a new normal (adjust the sales rate instead).
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', function (string $attr, mixed $value, \Closure $fail) {
                $start = $this->input('starts_on');
                if (is_string($start) && strtotime($value) - strtotime($start) > 92 * 86400) {
                    $fail('event_too_long');
                }
            }],
            // Sales x this on those days: 2 = double, 0.5 = half. 1 would change nothing.
            'multiplier' => ['required', 'numeric', 'min:0.1', 'max:10', 'not_in:1'],
            // A season: the same dates every year.
            'repeats_yearly' => ['sometimes', 'boolean'],
            'applies_to' => ['required', Rule::in([SalesEvent::ALL, SalesEvent::SUPPLIER, SalesEvent::PRODUCTS])],
            'supplier_id' => ['required_if:applies_to,supplier', 'nullable', 'integer', Rule::exists('suppliers', 'id')->where('shop_id', $shopId)],
            'variant_ids' => ['required_if:applies_to,products', 'nullable', 'array', 'max:500'],
            // Local ids or Shopify variant gids (resource picker).
            'variant_ids.*' => [function (string $attr, mixed $value, \Closure $fail) {
                if (! is_int($value) && ! (is_string($value) && preg_match('#^gid://shopify/ProductVariant/\d+$#', $value))) {
                    $fail('invalid_product');
                }
            }],
        ];
    }
}
