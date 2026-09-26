<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Enums\SyncType;
use App\Events\PlanChanged;
use App\Exceptions\ApiException;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Jobs\SyncRealtimeWebhook;
use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Shopify\BillingClient;
use App\Services\Sync\SyncService;
use App\Support\Entitlements;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Plan changes through the Shopify Billing API. Shopify is the source of truth:
 * after the merchant approves (or a webhook arrives) we re-read the active
 * subscription and derive plan + interval from its name.
 */
class BillingService
{
    public function __construct(
        private readonly BillingClient $billing,
        private readonly ShopRepositoryInterface $shops,
        private readonly CatalogRepositoryInterface $catalog,
    ) {}

    /** Plans for the pricing page. */
    public function catalog(): array
    {
        return collect(config('billing.plans'))->map(fn ($p, $key) => [
            'key' => $key,
            'name' => $p['name'],
            'prices' => $p['prices'],
            'currency' => config('billing.currency'),
            // Switched-off features (config/features.php) show as not included.
            'limits' => array_merge($p['limits'], array_map(fn () => false, array_filter(
                array_flip(array_keys($p['limits'])),
                fn ($_, $key) => ($f = Feature::tryFrom($key)) !== null && ! Features::enabled($f),
                ARRAY_FILTER_USE_BOTH,
            ))),
            'offered' => (bool) ($p['offered'] ?? true),
        ])->values()->all();
    }

    public function state(Shop $shop): array
    {
        $tracked = $this->catalog->countTrackedVariants($shop);

        return [
            'plan' => $shop->plan->value,
            'interval' => $shop->plan_interval?->value,
            'status' => $shop->subscription_status,
            'renews_at' => $shop->plan_renews_at?->toIso8601String(),
            'entitlements' => Entitlements::for($shop)->toArray(),
            'usage' => ['tracked_skus' => $tracked],
            'trial_days_left' => $this->trialDaysLeft($shop),
        ];
    }

    /**
     * Start a plan change. Paid plans return Shopify's confirmation URL (the merchant
     * approves the charge there); Free cancels the current subscription right away.
     *
     * @return array{confirmation_url: ?string}
     */
    public function change(Shop $shop, Plan $plan, ?PlanInterval $interval): array
    {
        if ($plan === Plan::Free) {
            if ($shop->subscription_id) {
                $this->billing->cancel($shop, $shop->subscription_id);
            }
            $this->apply($shop, Plan::Free, null, null, null, null);

            return ['confirmation_url' => null];
        }

        $interval ??= PlanInterval::Monthly;
        $config = config("billing.plans.{$plan->value}");
        // A plan taken off the pricing page takes no new subscribers; its subscribers can still switch interval.
        if (! ($config['offered'] ?? true) && $shop->plan !== $plan) {
            throw new ApiException('plan_unavailable', 422, ['plan' => $plan->value]);
        }

        $created = $this->billing->create(
            $shop,
            name: self::subscriptionName($plan, $interval),
            price: (float) $config['prices'][$interval->value],
            currency: config('billing.currency'),
            interval: $interval->shopifyInterval(),
            returnUrl: $this->returnUrl($shop),
            test: (bool) config('billing.test'),
            trialDays: $this->trialDaysLeft($shop),
        );

        Log::info('Subscription created, waiting for approval', ['shop' => $shop->domain, 'plan' => $plan->value, 'interval' => $interval->value]);

        return ['confirmation_url' => $created['confirmation_url']];
    }

    /** Re-read the active subscription from Shopify (after approval, or on app_subscriptions/update). */
    public function refresh(Shop $shop): Shop
    {
        $active = collect($this->billing->active($shop))->firstWhere('status', 'ACTIVE');
        $parsed = $active ? self::parseName($active['name']) : null;

        if ($active && $parsed) {
            return $this->apply($shop, $parsed[0], $parsed[1], $active['id'], $active['status'],
                $active['currentPeriodEnd'] ? Carbon::parse($active['currentPeriodEnd']) : null);
        }

        return $this->apply($shop, Plan::Free, null, null, null, null);
    }

    /**
     * Safety net for missed app_subscriptions/update webhooks: re-read the subscription
     * from Shopify and fix the plan if it drifted. A drift is logged as a warning (it
     * means a webhook was lost). @return bool whether the plan had drifted
     */
    public function reconcile(Shop $shop): bool
    {
        $before = [$shop->plan, $shop->plan_interval, $shop->subscription_id];
        $shop = $this->refresh($shop);
        $drifted = $before !== [$shop->plan, $shop->plan_interval, $shop->subscription_id];

        if ($drifted) {
            Log::warning('Billing drift fixed (missed webhook?)', [
                'shop' => $shop->domain,
                'from' => $before[0]?->value.($before[1] ? " ({$before[1]->value})" : ''),
                'to' => $shop->plan->value.($shop->plan_interval ? " ({$shop->plan_interval->value})" : ''),
            ]);
        }

        return $drifted;
    }

    /** Trial days still available: the full trial once, then whatever is left of it. */
    public function trialDaysLeft(Shop $shop): int
    {
        $days = (int) config('billing.trial_days');
        if ($shop->trial_started_at === null) {
            return $days;
        }

        return max(0, $days - (int) $shop->trial_started_at->diffInDays(now()));
    }

    /** "Clear Stock Starter (monthly)" - parsed back by parseName(). */
    public static function subscriptionName(Plan $plan, PlanInterval $interval): string
    {
        return config('shopify.app_name').' '.config("billing.plans.{$plan->value}.name")." ({$interval->value})";
    }

    /** @return array{0: Plan, 1: PlanInterval}|null */
    public static function parseName(string $name): ?array
    {
        foreach (config('billing.plans') as $key => $plan) {
            foreach (PlanInterval::cases() as $interval) {
                if (str_ends_with($name, "{$plan['name']} ({$interval->value})")) {
                    return [Plan::from($key), $interval];
                }
            }
        }

        return null;
    }

    private function apply(Shop $shop, Plan $plan, ?PlanInterval $interval, ?string $subscriptionId, ?string $status, ?Carbon $renewsAt): Shop
    {
        $previous = $shop->plan;
        $previousInterval = $shop->plan_interval;
        $shop = $this->shops->update($shop, [
            'plan' => $plan,
            'plan_interval' => $interval,
            'subscription_id' => $subscriptionId,
            'subscription_status' => $status,
            'plan_renews_at' => $renewsAt,
            // The trial clock starts with the first paid plan and never resets.
            'trial_started_at' => $shop->trial_started_at ?? ($plan !== Plan::Free ? now() : null),
        ]);

        if ($previous !== $plan) {
            Log::info('Plan changed', ['shop' => $shop->domain, 'from' => $previous?->value, 'to' => $plan->value]);

            $gainedLocations = ! Entitlements::for($shop->replicate()->forceFill(['plan' => $previous]))->has(Feature::Locations)
                && Entitlements::for($shop)->has(Feature::Locations);
            if ($gainedLocations && $shop->last_synced_at !== null) {
                // Per-location history is only fetched on Growth: re-import the full window
                // (forecasts are recomputed when that sync finishes).
                app(SyncService::class)->start($shop, SyncType::Manual, full: true);
            } else {
                // SKU limit, bundle demand and location forecasts depend on the plan.
                RecomputeForecasts::dispatch($shop->id);
            }
            // Real-time alerts are Growth only: subscribe or drop the inventory webhook.
            SyncRealtimeWebhook::dispatch($shop->id);
        }

        if ($previous !== $plan || $previousInterval !== $interval) {
            PlanChanged::dispatch($shop, $previous, $previousInterval, $plan, $interval);
        }

        return $shop;
    }

    /** Back into the embedded app after approval; the page re-reads the subscription. */
    private function returnUrl(Shop $shop): string
    {
        return "https://{$shop->domain}/admin/apps/".config('shopify.api_key').'/plans?confirmed=1';
    }
}
