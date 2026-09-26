<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OnboardingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lead_time_days' => ['required', 'integer', 'min:1', 'max:365'],
            'alert_email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }
}
