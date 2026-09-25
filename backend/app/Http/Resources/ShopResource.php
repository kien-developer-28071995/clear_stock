<?php

namespace App\Http\Resources;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shop */
class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'domain' => $this->domain,
            'name' => $this->name,
            'plan' => $this->plan->value,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'sync_status' => $this->sync_status->value,
            'sync_error' => $this->sync_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'installed_at' => $this->installed_at?->toIso8601String(),
        ];
    }
}
