<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Growth what-if: sales change in percent, planning horizon and an optional product filter. */
class GrowthScenarioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'growth' => ['required', 'integer', 'min:-90', 'max:500'],
            'horizon' => ['nullable', 'integer', Rule::in([0, 14, 30])],
            'supplier_id' => ['nullable', 'integer'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'abc' => ['nullable', Rule::in(['A', 'B', 'C'])],
        ];
    }
}
