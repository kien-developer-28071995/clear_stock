<?php

namespace App\Http\Requests;

use App\Enums\ForecastStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ForecastIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ForecastStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['urgency', 'cover', 'name', 'suggested', 'value'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer'],
        ];
    }
}
