<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Purchase plan: how many weeks ahead and an optional product filter. */
class PurchasePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'weeks' => ['nullable', 'integer', Rule::in([4, 8, 12])],
            'supplier_id' => ['nullable', 'integer'],
            'vendor' => ['nullable', 'string', 'max:255'],
        ];
    }
}
