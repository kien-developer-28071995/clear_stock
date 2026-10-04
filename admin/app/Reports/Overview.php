<?php

namespace App\Reports;

use App\Models\ShopEvent;
use App\Models\ShopRecord;
use Illuminate\Support\Carbon;

/** Numbers of the overview page, all from the report's own ledger. */
class Overview
{
    public function __construct(private readonly AppData $app) {}

    public function build(int $weeks = 12): array
    {
        $tz = config('report.timezone');
        $now = now($tz);
        $records = ShopRecord::query()->get();
        $installed = $records->whereNull('uninstalled_at');
        $uninstalled = $records->whereNotNull('uninstalled_at');
        $paying = $installed->where('plan', '!=', 'free');
        $mrr = round($installed->sum(fn (ShopRecord $r) => Pricing::mrr($r->plan, $r->plan_interval)), 2);

        // Shops that left: how long they stayed (latest install to uninstall).
        $stays = $uninstalled->map(fn (ShopRecord $r) => $r->daysInstalled())->filter(fn ($d) => $d !== null)->sort()->values();
        $activeSince = now()->subDays((int) config('report.active_days'));

        return [
            'now' => $now,
            'totals' => [
                'ever' => $records->count(),
                'installed' => $installed->count(),
                'uninstalled' => $uninstalled->count(),
                'deleted' => $records->whereNotNull('redacted_at')->count(),
                'paying' => $paying->count(),
                'trialing' => $paying->filter(fn (ShopRecord $r) => $r->trial_started_at !== null && $r->trial_started_at->gt(now()->subDays(7)))->count(),
                'mrr' => $mrr,
                'arr' => round($mrr * 12, 2),
                'onboarded' => $installed->whereNotNull('onboarded_at')->count(),
                'active' => $this->app->has('shops', ['forecasted_at']) ? $this->app->installedShops()->where('forecasted_at', '>=', $activeSince)->count() : null,
                'churn_rate' => $records->count() > 0 ? round($uninstalled->count() / $records->count(), 3) : null,
                'paid_rate' => $installed->count() > 0 ? round($paying->count() / $installed->count(), 3) : null,
            ],
            'plans' => collect(['free', 'starter', 'growth'])->mapWithKeys(fn ($plan) => [$plan => [
                'shops' => $installed->where('plan', $plan)->count(),
                'monthly' => $installed->where('plan', $plan)->where('plan_interval', 'monthly')->count(),
                'annual' => $installed->where('plan', $plan)->where('plan_interval', 'annual')->count(),
                'mrr' => round($installed->where('plan', $plan)->sum(fn (ShopRecord $r) => Pricing::mrr($r->plan, $r->plan_interval)), 2),
            ]])->all(),
            'stays' => [
                'median_days' => $stays->isEmpty() ? null : $stays[intdiv($stays->count(), 2)],
                'same_day' => $stays->filter(fn ($d) => $d < 1)->count(),
                'first_week' => $stays->filter(fn ($d) => $d >= 1 && $d < 7)->count(),
                'first_month' => $stays->filter(fn ($d) => $d >= 7 && $d < 30)->count(),
                'later' => $stays->filter(fn ($d) => $d >= 30)->count(),
                'paid_before' => $uninstalled->filter(fn (ShopRecord $r) => $r->plan_at_uninstall !== null && $r->plan_at_uninstall !== 'free')->count(),
            ],
            'weeks' => $this->weeks($weeks, $now),
            'recent' => ShopEvent::query()->with('shop')->orderByDesc('occurred_at')->orderByDesc('id')->limit(15)->get(),
        ];
    }

    /**
     * Installs (incl. reinstalls) and uninstalls per week, oldest first.
     *
     * @return array<int, array{start: string, installs: int, uninstalls: int, net: int}>
     */
    private function weeks(int $weeks, Carbon $now): array
    {
        $tz = config('report.timezone');
        $first = $now->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($weeks - 1);
        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $out[$first->copy()->addWeeks($i)->toDateString()] = ['start' => $first->copy()->addWeeks($i)->toDateString(), 'installs' => 0, 'uninstalls' => 0, 'net' => 0];
        }
        ShopEvent::query()->whereIn('type', [ShopEvent::INSTALLED, ShopEvent::REINSTALLED, ShopEvent::UNINSTALLED])
            ->where('occurred_at', '>=', $first->copy()->utc())->get(['type', 'occurred_at'])
            ->each(function (ShopEvent $e) use (&$out, $tz) {
                $week = $e->occurred_at->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->toDateString();
                if (isset($out[$week])) {
                    $key = $e->type === ShopEvent::UNINSTALLED ? 'uninstalls' : 'installs';
                    $out[$week][$key]++;
                    $out[$week]['net'] += $key === 'installs' ? 1 : -1;
                }
            });

        return array_values($out);
    }
}
