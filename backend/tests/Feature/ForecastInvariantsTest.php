<?php

use App\Models\DailySale;
use App\Models\Forecast;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use App\Support\ForecastStatusResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Whatever a shop looks like, some things must always hold. Shops are made at random (seeded, so a
// failure can be replayed): odd sales histories, negative stock, returns above sales, every order
// rule at once. The assertions are the promises the screens make to a merchant.

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
});

function randomShop(int $seed): Shop
{
    mt_srand($seed);
    $pick = fn (array $options) => $options[mt_rand(0, count($options) - 1)];
    $shop = Shop::factory()->create(['domain' => "random-{$seed}.myshopify.com", 'timezone' => $pick(['UTC', 'Asia/Ho_Chi_Minh', 'America/Los_Angeles', 'Pacific/Auckland']),
        'currency' => 'USD', 'plan' => $pick(['free', 'starter', 'growth']), 'default_lead_time_days' => $pick([1, 7, 14, 60, 365]), 'default_safety_days' => $pick([0, 7, 30]),
        'filter_sales_spikes' => (bool) mt_rand(0, 1), 'access_token_expires_at' => now()->addYear()]);
    $location = Location::factory()->for($shop)->create();
    $suppliers = collect(range(1, 3))->map(fn () => Supplier::factory()->for($shop)->create([
        'lead_time_days' => $pick([null, 0, 3, 45]), 'min_order_qty' => $pick([null, 1, 50]), 'pack_size' => $pick([null, 1, 12]),
        'order_cycle_days' => $pick([null, 7, 90]), 'order_weekdays' => $pick([null, [1], [2, 5], [7]]),
    ]));

    for ($n = 0; $n < 25; $n++) {
        $v = Variant::factory()->for($shop)->create([
            'product_title' => "Product {$n}", 'title' => 'Default Title', 'shopify_created_at' => $pick(['2020-01-01', '2026-09-01', '2026-09-19']),
            'supplier_id' => $pick([null, $suppliers[0]->id, $suppliers[1]->id, $suppliers[2]->id]),
            'unit_cost' => $pick([null, 0, 0.01, 12.5, 99999.99]), 'price' => $pick([null, 0, 19.99, 100000]),
            'lead_time_override' => $pick([null, null, 0, 1, 200]), 'safety_days' => $pick([null, null, 0, 90]),
            'min_order_qty' => $pick([null, null, 1, 7, 1000]), 'pack_size' => $pick([null, null, 1, 6, 144]),
            'min_stock' => $pick([null, null, 0, 5, 500]), 'max_stock' => $pick([null, null, 600, 5000]),
            'discontinued' => mt_rand(0, 9) === 0,
        ]);
        InventoryLevel::factory()->create(['shop_id' => $shop->id, 'variant_id' => $v->id, 'location_id' => $location->id, 'available' => $pick([-5, 0, 0, 1, 12, 300, 100000]), 'incoming' => $pick([0, 0, 0, 20, 5000])]);
        $shape = $pick(['none', 'steady', 'sparse', 'spiky', 'recent only', 'old only', 'returns']);
        $rows = [];
        for ($ago = 1; $ago <= 400; $ago++) {
            $sold = match ($shape) {
                'none' => 0,
                'steady' => mt_rand(3, 6),
                'sparse' => mt_rand(0, 20) === 0 ? mt_rand(1, 3) : 0,
                'spiky' => mt_rand(0, 30) === 0 ? mt_rand(200, 900) : mt_rand(0, 4),
                'recent only' => $ago <= 5 ? mt_rand(5, 40) : 0,
                'old only' => $ago > 200 ? mt_rand(5, 40) : 0,
                'returns' => mt_rand(0, 3),
            };
            if ($sold === 0 && $shape !== 'returns' && mt_rand(0, 2) > 0) {
                continue; // days without a row
            }
            $rows[] = ['shop_id' => $shop->id, 'variant_id' => $v->id, 'date' => CarbonImmutable::parse('2026-09-20')->subDays($ago)->toDateString(),
                'units_sold' => $sold, 'units_returned' => $shape === 'returns' ? mt_rand(0, 6) : 0, 'end_of_day_stock' => null, 'was_in_stock' => mt_rand(0, 6) > 0];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DailySale::insert($chunk);
        }
    }

    return $shop;
}

it('keeps its promises for any shop', function (int $seed) {
    $shop = randomShop($seed);
    app(ForecastService::class)->runForShop($shop);
    $auth = ['Authorization' => 'Bearer '.sessionToken($shop->domain)];
    $today = CarbonImmutable::now($shop->timezone)->toDateString();
    $problems = [];

    foreach (Forecast::query()->withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('location_id')->with('variant.supplier')->get() as $f) {
        $v = $f->variant;
        $name = "seed {$seed} {$v->product_title}";
        $check = function (bool $ok, string $what) use (&$problems, $name, $f) {
            if (! $ok) {
                $problems[] = "{$name}: {$what} ".json_encode($f->only(['avg_daily_sales', 'current_stock', 'incoming_stock', 'days_of_cover', 'stockout_date', 'reorder_point', 'reorder_date', 'suggested_qty']));
            }
        };
        $avg = (float) $f->avg_daily_sales;
        $check($avg >= 0 && is_finite($avg), 'sales rate is a real, non-negative number');
        $check($f->suggested_qty >= 0, 'never suggests a negative order');
        $check($f->reorder_point >= 0, 'reorder point is not negative');
        $check($f->days_of_cover === null || (float) $f->days_of_cover >= 0, 'days of stock is not negative');
        $check($f->stockout_date === null || $f->stockout_date->toDateString() >= $today, 'runs out today or later, never in the past');
        $check($avg > 0 || $f->suggested_qty === 0 || $v->min_stock !== null, 'nothing to order for a product that does not sell (unless a minimum is set)');
        $check($f->reorder_date === null || $f->reorder_date->toDateString() >= $today, 'never asks to reorder in the past');
        $check($f->reorder_date === null || $f->reorder_date->toDateString() <= CarbonImmutable::parse($today)->addYears(11)->toDateString(), 'no dates beyond the planning horizon');
        if ($v->discontinued) {
            $check($f->suggested_qty === 0, 'a discontinued product is never reordered');
        }
        if ($f->suggested_qty > 0) {
            $pack = $v->effectivePackSize();
            $moq = $v->effectiveMinOrderQty();
            $check($pack === null || $f->suggested_qty % $pack === 0, "order is whole packs of {$pack}");
            $check($moq === null || $f->suggested_qty >= $moq, "order meets the minimum of {$moq}");
            $check($f->reorder_date !== null || $v->min_stock !== null, 'an order has a date (or waits for the merchant\'s minimum)');
        }
        $check(is_array($f->explanation) && $f->explanation !== [], 'every forecast is explained');
        // The badge and the filters use the same definition.
        $status = ForecastStatusResolver::for($f, $today);
        $listed = $this->getJson("/api/forecasts?status={$status->value}&per_page=100", $auth)->json('data.*.variant_id') ?? [];
        $check($v->is_active === false || in_array($f->variant_id, $listed, true) || count($listed) === 100, "its badge ({$status->value}) is found under the same filter");
    }

    // Screens that add things up agree with themselves, and never show NaN or infinity.
    foreach (['/api/dashboard', '/api/forecasts?per_page=100', '/api/suppliers', '/api/manual-orders', '/api/stock-history', '/api/data-health', '/api/clearance', '/api/accuracy'] as $url) {
        $response = $this->getJson($url, $auth);
        if ($response->status() >= 500 || preg_match('/NaN|Infinity|INF\b/', (string) $response->getContent())) {
            $problems[] = "seed {$seed} {$url}: status {$response->status()} or a non-number in the answer";
        }
    }
    $counts = $this->getJson('/api/dashboard', $auth)->json('data.counts');
    foreach (['out_of_stock', 'reorder_now', 'overstock', 'slow', 'healthy'] as $status) {
        $total = $this->getJson("/api/forecasts?status={$status}", $auth)->json('meta.total');
        if ($total !== ($counts[$status] ?? null)) {
            $problems[] = "seed {$seed}: home says ".json_encode($counts[$status] ?? null)." {$status}, the list has {$total}";
        }
    }
    if ($shop->plan->value !== 'free') {
        $plan = $this->getJson('/api/purchase-plan?weeks=12', $auth)->assertOk()->json('data');
        $byWeek = array_sum(array_column($plan['by_week'], 'units'));
        $bySupplier = array_sum(array_column($plan['by_supplier'], 'units'));
        if ($byWeek !== $plan['totals']['units'] || $bySupplier !== $plan['totals']['units']) {
            $problems[] = "seed {$seed}: purchase plan totals {$plan['totals']['units']} units, weeks add up to {$byWeek}, suppliers to {$bySupplier}";
        }
        foreach ($plan['items'] as $item) {
            foreach ($item['orders'] as $o) {
                if ($o['qty'] <= 0 || $o['date'] < $plan['today'] || $o['date'] > $plan['until']) {
                    $problems[] = "seed {$seed}: planned order outside the plan or empty ".json_encode($o);
                }
            }
        }
        $whatIf = $this->getJson('/api/what-if?growth=50&horizon=30', $auth);
        if ($whatIf->status() >= 500) {
            $problems[] = "seed {$seed}: what-if failed";
        }
    }

    // The random shop is a real mix (or the checks above would prove nothing).
    $all = Forecast::query()->withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('location_id')->get();
    $mix = ['selling' => $all->where('avg_daily_sales', '>', 0)->count(), 'to order' => $all->where('suggested_qty', '>', 0)->count(), 'not selling' => $all->where('avg_daily_sales', '<=', 0)->count()];
    expect($mix['selling'])->toBeGreaterThanOrEqual(5, json_encode($mix))
        ->and($mix['to order'])->toBeGreaterThanOrEqual(2, json_encode($mix))
        ->and($mix['not selling'])->toBeGreaterThanOrEqual(1, json_encode($mix));

    expect(array_slice($problems, 0, 12))->toBe([]);
})->with(array_map(fn (int $seed) => [$seed], range(1, 30)));
