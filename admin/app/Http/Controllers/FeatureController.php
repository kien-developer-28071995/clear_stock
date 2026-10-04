<?php

namespace App\Http\Controllers;

use App\Models\DailyStat;
use App\Models\ShopRecord;
use App\Reports\FeatureUsage;
use Illuminate\View\View;

class FeatureController extends Controller
{
    public function __invoke(FeatureUsage $features): View
    {
        $installed = ShopRecord::query()->whereNull('uninstalled_at')->get(['app_shop_id', 'plan']);
        $plans = $installed->pluck('plan', 'app_shop_id');
        // Shops using each feature about 30 days ago, for the change column.
        $before = DailyStat::query()->where('date', '<=', now(config('report.timezone'))->subDays(30)->toDateString())->orderByDesc('date')->first();

        $rows = collect($features->all())->map(fn ($f, $key) => $f + [
            'key' => $key,
            'count' => count($f['shop_ids']),
            'share' => $installed->count() > 0 ? count($f['shop_ids']) / $installed->count() : 0,
            'by_plan' => collect($f['shop_ids'])->countBy(fn ($id) => $plans[$id] ?? 'free')->all(),
            'before' => $before?->features[$key] ?? null,
        ]);

        return view('features', [
            'groups' => $rows->groupBy('group'),
            'installed' => $installed->count(),
            'before_date' => $before?->date,
            // Installed shops by how many features they use: who is engaged, who only looked.
            'depth' => $installed->map(fn ($s) => $rows->filter(fn ($f) => ! in_array($f['key'], ['onboarded', 'synced'], true) && in_array($s->app_shop_id, $f['shop_ids'], true))->count())
                ->countBy(fn ($n) => match (true) {
                    $n === 0 => 'none', $n <= 2 => '1–2', $n <= 5 => '3–5', default => '6+'
                })->all(),
        ]);
    }
}
