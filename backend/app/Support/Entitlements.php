<?php

namespace App\Support;

use App\Enums\Feature;
use App\Enums\Plan;
use App\Exceptions\ApiException;
use App\Exceptions\PlanRequiredException;
use App\Models\Shop;

/**
 * What a shop can use: its plan (config/billing.php) AND the app-wide feature switches
 * (config/features.php). Single place to ask "can this shop use X?".
 */
final readonly class Entitlements
{
    private function __construct(public Plan $plan, private array $limits) {}

    public static function for(Shop $shop): self
    {
        $plan = $shop->plan ?? Plan::Free;

        return new self($plan, config("billing.plans.{$plan->value}.limits"));
    }

    public function has(Feature $feature): bool
    {
        return Features::enabled($feature) && (bool) ($this->limits[$feature->value] ?? false);
    }

    /**
     * Throws when the shop can't use the feature: 404 `feature_disabled` when it is switched
     * off app-wide (nothing to upgrade to), 402 `plan_required` when the plan lacks it.
     */
    public function require(Feature $feature): void
    {
        if (! Features::enabled($feature)) {
            throw ApiException::featureDisabled($feature->value);
        }
        if (! $this->has($feature)) {
            throw new PlanRequiredException($feature);
        }
    }

    /** null = unlimited */
    public function maxSkus(): ?int
    {
        return $this->limits['max_skus'] ?? null;
    }

    /**
     * For the frontend: plan limits with switched-off features as false, plus `features`
     * (app-wide switches) so the app can hide, rather than upsell, what is switched off.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $limits = $this->limits;
        foreach (Feature::cases() as $feature) {
            if (array_key_exists($feature->value, $limits) && ! Features::enabled($feature)) {
                $limits[$feature->value] = false;
            }
        }

        return ['plan' => $this->plan->value] + $limits + ['features' => Features::all()];
    }
}
