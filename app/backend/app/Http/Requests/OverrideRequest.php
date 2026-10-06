<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Send a field with value null to remove that override; omit it to leave it unchanged. */
class OverrideRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'avg_daily_sales' => ['nullable', 'array'],
            'avg_daily_sales.value' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lead_time_days' => ['nullable', 'array'],
            'lead_time_days.value' => ['nullable', 'integer', 'min:0', 'max:365'],
            'safety_days' => ['nullable', 'array'],
            'safety_days.value' => ['nullable', 'integer', 'min:0', 'max:365'],
            '*.note' => ['nullable', 'string', 'max:500'],
            '*.expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
