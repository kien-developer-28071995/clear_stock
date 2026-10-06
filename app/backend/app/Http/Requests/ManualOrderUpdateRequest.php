<?php

namespace App\Http\Requests;

use App\Models\ManualOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Receive, cancel, reopen or reschedule an order placed outside Shopify. */
class ManualOrderUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([ManualOrder::OPEN, ManualOrder::RECEIVED, ManualOrder::CANCELLED])],
            'expected_on' => ['sometimes', 'date_format:Y-m-d'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            // Units delivered so far (a partial delivery); reaching the ordered quantity receives the order.
            'received_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
