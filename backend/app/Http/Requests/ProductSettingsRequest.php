<?php

namespace App\Http\Requests;

/** Bulk settings for the products selected in the Shopify product list (admin extension). */
class ProductSettingsRequest extends VariantSettingsRequest
{
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:250'],
            'product_ids.*' => ['required', 'string', 'regex:#^gid://shopify/Product/\d+$#'],
        ] + array_intersect_key(parent::rules(), array_flip(['supplier_id', 'lead_time_override', 'safety_days']));
    }

    public function settings(): array
    {
        return $this->safe()->only(['supplier_id', 'lead_time_override', 'safety_days']);
    }
}
