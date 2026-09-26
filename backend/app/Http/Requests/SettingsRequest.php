<?php

namespace App\Http\Requests;

use App\Enums\AlertFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'default_lead_time_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'default_safety_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'alerts' => ['sometimes', 'array'],
            'alerts.email' => ['nullable', 'email:rfc', 'max:255'],
            'alerts.enabled' => ['sometimes', 'boolean'],
            'alerts.frequency' => ['sometimes', Rule::enum(AlertFrequency::class)],
            'alerts.weekly_day' => ['sometimes', 'integer', 'between:1,7'],
        ];
    }
}
