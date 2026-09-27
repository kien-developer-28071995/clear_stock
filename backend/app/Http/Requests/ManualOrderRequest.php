<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Mark products as ordered outside Shopify. */
class ManualOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'expected_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
