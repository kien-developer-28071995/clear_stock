<?php

namespace App\Http\Requests;

use App\Enums\AlertFrequency;
use App\Enums\RealtimeAlertMode;
use App\Support\Locales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'default_lead_time_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'default_safety_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            // null = switched off app-wide (the settings page sends back what it got)
            'filter_sales_spikes' => ['sometimes', 'nullable', 'boolean'],
            'forecast_profile' => ['sometimes', Rule::in(array_keys(config('forecast.profiles')))],
            // null = follow the Shopify admin language
            'locale' => ['sometimes', 'nullable', Rule::in(Locales::supported())],
            'alerts' => ['sometimes', 'array'],
            'alerts.email' => ['nullable', 'email:rfc', 'max:255'],
            'alerts.enabled' => ['sometimes', 'boolean'],
            'alerts.frequency' => ['sometimes', Rule::enum(AlertFrequency::class)],
            'alerts.weekly_day' => ['sometimes', 'integer', 'between:1,7'],
            'alerts.realtime' => ['sometimes', Rule::enum(RealtimeAlertMode::class)],
        ];
    }
}
