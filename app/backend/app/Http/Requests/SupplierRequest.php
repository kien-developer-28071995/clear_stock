<?php

namespace App\Http\Requests;

use App\Support\ShopContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function rules(): array
    {
        $shopId = app(ShopContext::class)->shop()->id;
        $id = $this->route('supplier');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->where('shop_id', $shopId)->ignore($id)],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // Defaults for the supplier's products (a product's own setting wins).
            'min_order_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'pack_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            // How often orders go to this supplier: an order covers this many days of sales (null = app default).
            'order_cycle_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            // Weekdays orders are placed with this supplier (ISO: 1 = Monday ... 7 = Sunday); empty/null = any day.
            'order_weekdays' => ['sometimes', 'nullable', 'array', 'max:7'],
            'order_weekdays.*' => ['integer', 'between:1,7'], // duplicates are dropped
            // Freight, duty and handling on top of the supplier's price (%); the supplier's minimum order value.
            'landed_cost_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'min_order_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            // Automatic purchase order emails (Growth); needs a supplier email.
            'auto_email' => ['sometimes', 'boolean'],
        ];
    }

    /** Sorted weekdays, or null for "any day". */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if ($key === null && array_key_exists('order_weekdays', $data)) {
            $days = array_values(array_unique(array_map('intval', $data['order_weekdays'] ?? [])));
            sort($days);
            $data['order_weekdays'] = $days === [] || count($days) === 7 ? null : $days;
        }

        return $data;
    }
}
