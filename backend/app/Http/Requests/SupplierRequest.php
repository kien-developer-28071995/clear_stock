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
            // Automatic purchase order emails (Growth); needs a supplier email.
            'auto_email' => ['sometimes', 'boolean'],
        ];
    }
}
