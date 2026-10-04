<?php

namespace App\Http\Controllers;

use App\Models\ShopRecord;
use App\Reports\FeatureUsage;
use App\Reports\Pricing;
use App\Reports\ShopDetail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopController extends Controller
{
    private const SORTS = ['installed_at', 'uninstalled_at', 'name', 'plan'];

    public function index(Request $request, FeatureUsage $features): View
    {
        $filters = $this->filters($request);
        $all = $features->all();

        return view('shops.index', [
            'shops' => $this->query($filters, $all)->paginate(50)->withQueryString(),
            'filters' => $filters,
            'features' => $all,
            'counts' => [
                'installed' => ShopRecord::query()->whereNull('uninstalled_at')->count(),
                'uninstalled' => ShopRecord::query()->whereNotNull('uninstalled_at')->count(),
            ],
        ]);
    }

    public function show(ShopRecord $shop, ShopDetail $detail): View
    {
        return view('shops.show', ['shop' => $shop->load('events'), 'mrr' => Pricing::mrr($shop->plan, $shop->plan_interval)] + $detail->build($shop));
    }

    public function export(Request $request, FeatureUsage $features): StreamedResponse
    {
        $all = $features->all();
        $query = $this->query($this->filters($request), $all);

        return response()->streamDownload(function () use ($query, $all) {
            $out = fopen('php://output', 'w');
            // A shop name is the merchant's text: one starting with = + - @ must not run as a formula in a spreadsheet.
            $safe = fn ($cell) => is_string($cell) && $cell !== '' && ! is_numeric($cell) && strpbrk($cell[0], "=+-@\t\r") !== false ? "'".$cell : $cell;
            fputcsv($out, ['Shop', 'Domain', 'Status', 'Plan', 'Billing', 'MRR', 'Installed', 'Uninstalled', 'Days installed', 'Plan at uninstall', 'Onboarded', 'Features used'], escape: '');
            $query->chunk(500, function ($shops) use ($out, $all, $safe) {
                foreach ($shops as $s) {
                    $used = array_keys(array_filter($all, fn ($f) => in_array($s->app_shop_id, $f['shop_ids'], true)));
                    fputcsv($out, array_map($safe, [
                        $s->label(), $s->domain, $s->status(), $s->plan, $s->plan_interval, Pricing::mrr($s->plan, $s->plan_interval),
                        $s->installed_at?->toDateString(), $s->uninstalled_at?->toDateString(), $s->daysInstalled(), $s->plan_at_uninstall,
                        $s->onboarded_at?->toDateString(), implode(' | ', $used),
                    ]), escape: '');
                }
            });
            fclose($out);
        }, 'shops-'.now(config('report.timezone'))->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{status: string, plan: string, feature: string, q: string, sort: string} */
    private function filters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'installed_at');

        return [
            'status' => in_array($request->query('status'), ['installed', 'uninstalled', 'deleted'], true) ? $request->query('status') : '',
            'plan' => in_array($request->query('plan'), ['free', 'starter', 'growth'], true) ? $request->query('plan') : '',
            'feature' => (string) $request->query('feature', ''),
            'q' => trim((string) $request->query('q', '')),
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'installed_at',
        ];
    }

    /** @param array<string, array{shop_ids: array<int, int>}> $features */
    private function query(array $filters, array $features): Builder
    {
        return ShopRecord::query()
            ->when($filters['status'] === 'installed', fn ($q) => $q->whereNull('uninstalled_at'))
            ->when($filters['status'] === 'uninstalled', fn ($q) => $q->whereNotNull('uninstalled_at'))
            ->when($filters['status'] === 'deleted', fn ($q) => $q->whereNotNull('redacted_at'))
            ->when($filters['plan'] !== '', fn ($q) => $q->where('plan', $filters['plan']))
            ->when(isset($features[$filters['feature']]), fn ($q) => $q->whereIn('app_shop_id', $features[$filters['feature']]['shop_ids']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $q->where(fn ($w) => $w->where('domain', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->when(in_array($filters['sort'], ['name', 'plan'], true), fn ($q) => $q->orderBy($filters['sort']), fn ($q) => $q->orderByDesc($filters['sort']))
            ->orderByDesc('id');
    }
}
