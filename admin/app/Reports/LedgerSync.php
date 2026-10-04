<?php

namespace App\Reports;

use App\Models\DailyStat;
use App\Models\ShopEvent;
use App\Models\ShopRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copies what the app's `shops` table says now into the report's own ledger, recording what
 * changed since the last run as events (installed, uninstalled, reinstalled, plan changed).
 *
 * The app deletes a shop 48 hours after an uninstall (shop/redact). The ledger keeps that shop's
 * dates and plans, without its name or domain, so "how many shops uninstalled" stays answerable.
 */
class LedgerSync
{
    public function __construct(private readonly AppData $app, private readonly FeatureUsage $features) {}

    /** @return array{shops: int, installed: int, uninstalled: int, reinstalled: int, plan_changed: int, redacted: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $stats = ['shops' => 0, 'installed' => 0, 'uninstalled' => 0, 'reinstalled' => 0, 'plan_changed' => 0, 'redacted' => 0];
        $seen = [];
        $has = fn (string $column) => $this->app->has('shops', [$column]);

        DB::transaction(function () use (&$stats, &$seen, $now, $has) {
            $records = ShopRecord::query()->get()->keyBy('app_shop_id');

            foreach ($this->app->table('shops')->orderBy('id')->cursor() as $row) {
                $stats['shops']++;
                $seen[(int) $row->id] = true;
                $installedAt = $this->time($row->installed_at ?? $row->created_at ?? null) ?? $now;
                $uninstalledAt = $this->time($row->uninstalled_at ?? null);
                $attributes = [
                    'domain' => $row->domain,
                    'name' => $row->name ?? null,
                    'currency' => $row->currency ?? null,
                    'installed_at' => $installedAt,
                    'uninstalled_at' => $uninstalledAt,
                    'onboarded_at' => $has('onboarded_at') ? $this->time($row->onboarded_at) : null,
                    'trial_started_at' => $has('trial_started_at') ? $this->time($row->trial_started_at) : null,
                    'redacted_at' => null,
                ];
                $plan = (string) ($row->plan ?? 'free');
                $interval = $has('plan_interval') ? $row->plan_interval : null;
                /** @var ?ShopRecord $record */
                $record = $records->get((int) $row->id);

                if ($record === null) {
                    $record = ShopRecord::query()->create($attributes + ['app_shop_id' => $row->id, 'plan' => $plan, 'plan_interval' => $interval, 'first_installed_at' => $installedAt]);
                    $this->event($record, ShopEvent::INSTALLED, $installedAt, to: $plan, interval: $interval, mrr: $uninstalledAt === null ? Pricing::mrr($plan, $interval) : 0);
                    $stats['installed']++;
                    if ($uninstalledAt !== null) {
                        $this->event($record, ShopEvent::UNINSTALLED, $uninstalledAt, from: $plan);
                        $stats['uninstalled']++;
                    }

                    continue;
                }

                $wasInstalled = $record->uninstalled_at === null;
                if ($wasInstalled && $uninstalledAt !== null) {
                    // The app resets the plan to Free on uninstall: the ledger still has the plan it had.
                    $this->event($record, ShopEvent::UNINSTALLED, $uninstalledAt, from: $record->plan, interval: $record->plan_interval, mrr: -Pricing::mrr($record->plan, $record->plan_interval));
                    $attributes['plan_at_uninstall'] = $record->plan;
                    $stats['uninstalled']++;
                } elseif (! $wasInstalled && $uninstalledAt === null) {
                    $this->event($record, ShopEvent::REINSTALLED, $installedAt, to: $plan, interval: $interval, mrr: Pricing::mrr($plan, $interval));
                    $stats['reinstalled']++;
                } elseif ($uninstalledAt === null && ($record->plan !== $plan || $record->plan_interval !== $interval)) {
                    $this->event($record, ShopEvent::PLAN_CHANGED, $now, from: $record->plan, to: $plan, interval: $interval,
                        mrr: Pricing::mrr($plan, $interval) - Pricing::mrr($record->plan, $record->plan_interval));
                    $stats['plan_changed']++;
                }

                $record->update($attributes + ['plan' => $plan, 'plan_interval' => $interval]);
            }

            // Gone from the app: its data was deleted after the uninstall. Keep dates and plans only.
            foreach ($records as $appShopId => $record) {
                if (isset($seen[$appShopId]) || $record->redacted_at !== null) {
                    continue;
                }
                if ($record->uninstalled_at === null) {
                    $this->event($record, ShopEvent::UNINSTALLED, $now, from: $record->plan, interval: $record->plan_interval, mrr: -Pricing::mrr($record->plan, $record->plan_interval));
                    $record->plan_at_uninstall = $record->plan;
                    $record->uninstalled_at = $now;
                    $stats['uninstalled']++;
                }
                $record->fill(['domain' => null, 'name' => null, 'redacted_at' => $now, 'plan' => 'free', 'plan_interval' => null])->save();
                $this->event($record, ShopEvent::REDACTED, $now);
                $stats['redacted']++;
            }
        });

        $this->features->forget();
        $this->snapshot($now);

        return $stats;
    }

    /** Today's totals (replaced on every run of the day). */
    private function snapshot(Carbon $now): void
    {
        $installed = ShopRecord::query()->whereNull('uninstalled_at')->get(['app_shop_id', 'plan', 'plan_interval']);
        $activeSince = $now->copy()->subDays((int) config('report.active_days'));
        $active = $this->app->has('shops', ['forecasted_at'])
            ? $this->app->installedShops()->where('forecasted_at', '>=', $activeSince)->count()
            : 0;

        // whereDate: the column holds a date, whatever time part the database driver stores with it.
        $date = $now->copy()->setTimezone(config('report.timezone'))->toDateString();
        (DailyStat::query()->whereDate('date', $date)->first() ?? new DailyStat(['date' => $date]))->fill([
            'installed' => $installed->count(),
            'active' => $active,
            'paying' => $installed->where('plan', '!=', 'free')->count(),
            'mrr' => round($installed->sum(fn (ShopRecord $r) => Pricing::mrr($r->plan, $r->plan_interval)), 2),
            'plans' => $installed->countBy('plan')->all(),
            'features' => array_map(fn ($f) => count($f['shop_ids']), array_filter($this->features->all(), fn ($f) => $f['available'])),
        ])->save();
    }

    private function event(ShopRecord $record, string $type, Carbon $at, ?string $from = null, ?string $to = null, ?string $interval = null, float $mrr = 0): void
    {
        $record->events()->create(['type' => $type, 'plan_from' => $from, 'plan_to' => $to, 'interval' => $interval, 'mrr_change' => round($mrr, 2), 'occurred_at' => $at]);
    }

    /** The app stores UTC timestamps. */
    private function time(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value, 'UTC');
    }
}
