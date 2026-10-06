<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A draft transfer to create in Shopify (from a suggestion, quantities editable). */
class TransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'origin_location_id' => ['required', 'integer'],
            'destination_location_id' => ['required', 'integer', 'different:origin_location_id'],
            'items' => ['required', 'array', 'min:1', 'max:250'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            // Sent by the app so a retried click never creates two transfers.
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
        ];
    }
}
