<?php

namespace App\Http\Resources;

use App\Models\Shop;
use App\Services\App\ReviewPromptService;
use App\Support\Entitlements;
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
            'plan_interval' => $this->plan_interval?->value,
            'entitlements' => Entitlements::for($this->resource)->toArray(),
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'locale' => $this->locale,                  // null = follow the Shopify admin language
            'sync_status' => $this->sync_status->value,
            'sync_error' => $this->sync_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'installed_at' => $this->installed_at?->toIso8601String(),
            'onboarded' => $this->onboarded_at !== null,
            'default_lead_time_days' => $this->default_lead_time_days,
            'default_safety_days' => $this->default_safety_days,
            'forecasted_at' => $this->forecasted_at?->toIso8601String(),
            // The app may ask for an App Store review now (once, after a finished task).
            'review_prompt' => app(ReviewPromptService::class)->eligible($this->resource),
        ];
    }
}
