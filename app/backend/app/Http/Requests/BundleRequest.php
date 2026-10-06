<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Variants are local ids or Shopify variant gids (App Bridge resource picker). */
class BundleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bundle' => ['required'],
            'components' => ['required', 'array', 'min:1', 'max:50'],
            'components.*.variant' => ['required', 'distinct'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
