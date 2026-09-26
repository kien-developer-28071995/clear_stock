<?php

namespace App\Http\Resources;

use App\Models\BundleComponent;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A bundle variant with its components. Shopify-defined components are read-only. @mixin Variant */
class BundleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'variant_id' => $this->id,
            'shopify_variant_id' => $this->shopify_variant_id,
            'name' => $this->displayName(),
            'sku' => $this->sku,
            'components' => $this->bundleComponents->map(fn (BundleComponent $c) => [
                'variant_id' => $c->component_variant_id,
                'shopify_variant_id' => $c->component?->shopify_variant_id,
                'name' => $c->component?->displayName(),
                'sku' => $c->component?->sku,
                'quantity' => $c->quantity,
                'source' => $c->source,
            ])->values(),
            'editable' => $this->bundleComponents->every(fn ($c) => $c->source === BundleComponent::SOURCE_MANUAL),
        ];
    }
}
