<?php

namespace App\Http\Resources;

use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Variant */
class VariantOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shopify_variant_id' => $this->shopify_variant_id,
            'name' => $this->displayName(),
            'sku' => $this->sku,
            'is_bundle' => $this->is_bundle,
        ];
    }
}
