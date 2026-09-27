<?php

namespace App\Http\Resources;

use App\Models\SalesEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalesEvent */
class SalesEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'multiplier' => (float) $this->multiplier,
            'applies_to' => $this->applies_to,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier?->name,
            'variant_ids' => $this->variant_ids ?? [],
        ];
    }
}
