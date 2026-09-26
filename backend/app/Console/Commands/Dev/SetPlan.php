<?php

namespace App\Console\Commands\Dev;

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use Illuminate\Console\Command;

/**
 * DEV ONLY. Switches a shop's plan without Shopify billing, then recomputes its
 * forecasts (the SKU limit and bundle demand depend on the plan). Used by the
 * end-to-end tests to go through every plan.
 */
class SetPlan extends Command
{
    protected $signature = 'dev:set-plan
        {plan : free, starter or growth}
        {--shop= : Shop domain (default: first installed shop)}
        {--interval=monthly : monthly or annual (paid plans)}';

    protected $description = '[DEV] Switch a shop\'s plan without billing';

    public function handle(ShopRepositoryInterface $shops): int
    {
        if (! app()->environment('local')) {
            $this->error('This command only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $plan = Plan::tryFrom((string) $this->argument('plan'));
        $interval = PlanInterval::tryFrom((string) $this->option('interval'));
        $shop = $this->option('shop') ? Shop::firstWhere('domain', $this->option('shop')) : Shop::query()->whereNull('uninstalled_at')->first();
        if ($plan === null || $interval === null || $shop === null) {
            $this->error('Unknown plan, interval or shop.');

            return self::FAILURE;
        }

        $shops->update($shop, [
            'plan' => $plan,
            'plan_interval' => $plan === Plan::Free ? null : $interval,
            'subscription_id' => null,
            'subscription_status' => null,
            'plan_renews_at' => null,
        ]);
        RecomputeForecasts::dispatchSync($shop->id);

        $this->info("{$shop->domain} is now on {$plan->value}.");

        return self::SUCCESS;
    }
}
