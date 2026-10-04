<?php

namespace App\Reports;

/** What needs the owner's attention in the running app: failed syncs, stale forecasts, failed emails and jobs. */
class Health
{
    public function __construct(private readonly AppData $app) {}

    /** Built for Shopify thresholds (75th percentile over 28 days, at least 100 measurements). */
    private const VITALS = ['LCP' => 2500, 'CLS' => 0.1, 'INP' => 200];

    /**
     * 75th percentile of each web vital the Shopify admin measured over the last 28 days.
     *
     * @return array<int, array{metric: string, p75: ?float, limit: float, samples: int, ok: ?bool, enough: bool}>
     */
    private function webVitals(): array
    {
        $since = now()->subDays(28);
        $out = [];
        foreach (self::VITALS as $metric => $limit) {
            $query = fn () => $this->app->table('web_vitals')->where('metric', $metric)->where('created_at', '>=', $since);
            $n = $query()->count();
            // The value three quarters of the way up the sorted measurements.
            $p75 = $n === 0 ? null : (float) $query()->orderBy('value')->offset((int) floor(0.75 * ($n - 1)))->limit(1)->value('value');
            $out[] = ['metric' => $metric, 'p75' => $p75, 'limit' => $limit, 'samples' => $n, 'ok' => $p75 === null ? null : $p75 <= $limit, 'enough' => $n >= 100];
        }

        return $out;
    }

    public function build(): array
    {
        $app = $this->app;
        $staleBefore = now()->subHours(36);
        $weekAgo = now()->subDays(7);

        return [
            'failed_syncs' => $app->has('shops', ['sync_status', 'sync_error', 'last_synced_at'])
                ? $app->installedShops()->where('sync_status', 'failed')->orderBy('last_synced_at')->get(['id', 'domain', 'name', 'sync_error', 'last_synced_at'])
                    ->map(fn ($s) => ['id' => (int) $s->id, 'label' => $s->name ?: $s->domain, 'error' => json_decode((string) $s->sync_error, true)['code'] ?? null, 'last_synced_at' => $s->last_synced_at])->all()
                : null,
            'stale_forecasts' => $app->has('shops', ['forecasted_at', 'onboarded_at'])
                ? $app->installedShops()->whereNotNull('onboarded_at')->where(fn ($q) => $q->whereNull('forecasted_at')->orWhere('forecasted_at', '<', $staleBefore))
                    ->orderBy('forecasted_at')->get(['id', 'domain', 'name', 'forecasted_at'])
                    ->map(fn ($s) => ['id' => (int) $s->id, 'label' => $s->name ?: $s->domain, 'forecasted_at' => $s->forecasted_at])->all()
                : null,
            'never_synced' => $app->has('shops', ['last_synced_at', 'installed_at'])
                ? $app->installedShops()->whereNull('last_synced_at')->where('installed_at', '<', now()->subHours(2))->count()
                : null,
            'sync_runs' => $app->has('sync_runs', ['status', 'created_at'])
                ? $app->table('sync_runs')->where('created_at', '>=', $weekAgo)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all()
                : null,
            'emails' => $app->has('email_logs', ['status', 'mailable', 'created_at'])
                ? $app->table('email_logs')->where('created_at', '>=', $weekAgo)->selectRaw('mailable, status, COUNT(*) as n')->groupBy('mailable', 'status')->get()
                    ->groupBy('mailable')->map(fn ($rows, $mailable) => [
                        'type' => class_basename((string) $mailable),
                        'sent' => (int) ($rows->firstWhere('status', 'sent')->n ?? 0),
                        'failed' => (int) ($rows->firstWhere('status', 'failed')->n ?? 0),
                    ])->values()->all()
                : null,
            'web_vitals' => $app->has('web_vitals', ['metric', 'value', 'created_at']) ? $this->webVitals() : null,
            'failed_jobs' => $app->has('failed_jobs', ['failed_at'])
                ? ['total' => $app->table('failed_jobs')->count(), 'week' => $app->table('failed_jobs')->where('failed_at', '>=', $weekAgo)->count()]
                : null,
        ];
    }
}
