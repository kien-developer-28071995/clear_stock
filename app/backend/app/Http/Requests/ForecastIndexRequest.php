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
            'sort' => ['nullable', Rule::in(['urgency', 'cover', 'name', 'suggested', 'value', 'revenue', 'profit'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'product_type' => ['nullable', 'string', 'max:255'],
            'abc' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'trend' => ['nullable', Rule::in(['up', 'down'])],
        ];
    }
}
