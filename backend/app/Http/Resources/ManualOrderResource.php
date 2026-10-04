<?php

namespace App\Http\Resources;

use App\Models\ManualOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ManualOrder */
class ManualOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'name' => $this->variant?->displayName(),
            'sku' => $this->variant?->sku,
            'supplier' => $this->supplier?->name,
            'quantity' => $this->quantity,
            'received_quantity' => $this->received_quantity,
            'ordered_on' => $this->ordered_on->toDateString(),
            'expected_on' => $this->expected_on->toDateString(),
            'reference' => $this->reference,
            'source' => $this->source,
            // open, late (still counted), overdue (no longer counted), received, cancelled
            'state' => $this->state((string) $request->attributes->get('today', now()->toDateString())),
        ];
    }
}
