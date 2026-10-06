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
        // How many bundles the components on hand make, and the component that runs out first.
        $stock = $request->attributes->get('stock');
        $buildable = null;
        $limiting = null;
        foreach ($stock === null ? [] : $this->bundleComponents as $c) {
            $makes = intdiv(max(0, (int) ($stock[$c->component_variant_id] ?? 0)), max(1, $c->quantity));
            if ($buildable === null || $makes < $buildable) {
                [$buildable, $limiting] = [$makes, $c->component?->displayName()];
            }
        }

        return [
            'variant_id' => $this->id,
            'buildable' => $buildable,
            'limiting_component' => $limiting,
            'shopify_variant_id' => $this->shopify_variant_id,
            'name' => $this->displayName(),
            'sku' => $this->sku,
            'components' => $this->bundleComponents->map(fn (BundleComponent $c) => [
                'variant_id' => $c->component_variant_id,
                'shopify_variant_id' => $c->component?->shopify_variant_id,
                'name' => $c->component?->displayName(),
                'sku' => $c->component?->sku,
                'quantity' => $c->quantity,
                'stock' => $stock === null ? null : (int) ($stock[$c->component_variant_id] ?? 0),
                'source' => $c->source,
            ])->values(),
            'editable' => $this->bundleComponents->every(fn ($c) => $c->source === BundleComponent::SOURCE_MANUAL),
        ];
    }
}
