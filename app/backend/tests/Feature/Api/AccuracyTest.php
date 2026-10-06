<?php

use App\Models\DailySale;
use App\Models\Location;
use App\Models\Shop;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00'); // a Sunday
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'free']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];

    $this->mug = product($this->shop, $this->location, 'Mug', stock: 100, perDay: 4);
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 100, perDay: 2);
});

function snapshot(Shop $shop, int $variantId, string $week, float $avg, string $source = 'computed'): void
{
    DB::table('forecast_snapshots')->insert(['shop_id' => $shop->id, 'variant_id' => $variantId, 'week_start' => $week,
        'avg_daily_sales' => $avg, 'avg_source' => $source, 'has_bundles' => false, 'created_at' => now()]);
}

it('keeps the first forecast of each week and prunes old weeks', function () {
    snapshot($this->shop, $this->mug->id, '2026-05-04', 1); // older than 16 weeks
    app(ForecastService::class)->runForShop($this->shop);
    DailySale::where('variant_id', $this->mug->id)->update(['units_sold' => 8]);
    app(ForecastService::class)->runForShop($this->shop);

    $rows = DB::table('forecast_snapshots')->orderBy('variant_id')->get();
    expect($rows)->toHaveCount(2)
        ->and(substr($rows[0]->week_start, 0, 10))->toBe('2026-09-14')   // Monday of this week
        ->and((float) $rows[0]->avg_daily_sales)->toBe(4.0);              // the week's first run wins
});

it('says when the first result comes while it is still collecting', function () {
    app(ForecastService::class)->runForShop($this->shop);

    $this->getJson('/api/accuracy', $this->auth)->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.first_result_on', '2026-10-12');
});

it('compares past forecasts with what really sold, for the shop and per product', function () {
    // Four weeks ago Mug was forecast at 5/day and Cup at 2/day; they sold 4 and 2.
    snapshot($this->shop, $this->mug->id, '2026-08-17', 5);
    snapshot($this->shop, $this->cup->id, '2026-08-17', 2);
    snapshot($this->shop, $this->mug->id, '2026-08-10', 4, 'override');
    app(ForecastService::class)->runForShop($this->shop);

    $data = $this->getJson('/api/accuracy', $this->auth)->assertOk()->json('data');

    expect($data['available'])->toBeTrue()
        ->and($data['latest'])->toMatchArray(['week_start' => '2026-08-17', 'week_end' => '2026-09-13', 'products' => 2, 'accuracy' => 0.833, 'bias' => 0.167])
        ->and($data['latest']['top_misses'][0])->toMatchArray(['name' => 'Mug', 'predicted' => 5.0, 'actual' => 4.0])
        ->and($data['trend'])->toHaveCount(2)
        ->and($data['trend'][0])->toMatchArray(['week_start' => '2026-08-10', 'products' => 1, 'accuracy' => 1.0]);

    $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertOk()
        ->assertJsonPath('data.accuracy.week_start', '2026-08-17')
        ->assertJsonPath('data.accuracy.predicted', 5)
        ->assertJsonPath('data.accuracy.actual', 4);
});

it('can be switched off app-wide', function () {
    config(['features.accuracy' => false]);
    $this->getJson('/api/accuracy', $this->auth)->assertNotFound()->assertJsonPath('code', 'feature_disabled');
});
