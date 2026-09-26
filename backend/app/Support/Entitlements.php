<?php

namespace App\Support;

use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\Shop;

/** What a shop's plan allows. Single place to ask "can this shop use X?". */
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
        return (bool) ($this->limits[$feature->value] ?? false);
    }

    /** null = unlimited */
    public function maxSkus(): ?int
    {
        return $this->limits['max_skus'] ?? null;
    }

    /** @return array<string, bool|int|null> for the frontend */
    public function toArray(): array
    {
        return ['plan' => $this->plan->value] + $this->limits;
    }
}
