<?php

use App\Models\DailySale;
use App\Models\SalesEvent;
use App\Models\Shop;
use App\Models\Variant;
use App\Services\App\PeakSeasonAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-10-07 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'plan' => 'free', 'access_token_expires_at' => now()->addYear()]);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->variant = Variant::factory()->for($this->shop)->create();
});

/** Units a day from one date to another (both included). */
function sold(Shop $shop, Variant $variant, string $from, string $to, int $perDay): void
{
    $rows = [];
    for ($day = CarbonImmutable::parse($from); $day <= CarbonImmutable::parse($to); $day = $day->addDay()) {
        $rows[] = ['shop_id' => $shop->id, 'variant_id' => $variant->id, 'date' => $day->toDateString(),
            'units_sold' => $perDay, 'units_returned' => 0, 'end_of_day_stock' => null, 'was_in_stock' => true];
    }
    DailySale::insert($rows);
}

it('knows the Black Friday weekend of a year', function () {
    expect(array_map(fn ($d) => $d->toDateString(), PeakSeasonAdvisor::blackFridayWeekend(2025)))->toBe(['2025-11-28', '2025-12-01'])
        ->and(array_map(fn ($d) => $d->toDateString(), PeakSeasonAdvisor::blackFridayWeekend(2026)))->toBe(['2026-11-27', '2026-11-30']);
});

it('suggests the coming Black Friday weekend from what sold in it last year', function () {
    sold($this->shop, $this->variant, '2025-10-01', '2025-11-27', 4);
    sold($this->shop, $this->variant, '2025-11-28', '2025-12-01', 12);

    $this->getJson('/api/sales-events/suggestions', $this->auth)->assertOk()->assertExactJson(['data' => [[
        'key' => 'bfcm',
        'starts_on' => '2026-11-27',
        'ends_on' => '2026-11-30',
        'multiplier' => 3,
        'last_year' => ['starts_on' => '2025-11-28', 'ends_on' => '2025-12-01', 'units_per_day' => 12, 'usual_per_day' => 4],
    ]]]);
});

it('suggests nothing without last year, without a real rise, or once the merchant has an event for those days', function () {
    $none = fn () => $this->getJson('/api/sales-events/suggestions', $this->auth)->assertOk()->assertExactJson(['data' => []]);

    // The shop only started selling after the weeks the peak is compared with.
    sold($this->shop, $this->variant, '2025-11-10', '2025-12-01', 12);
    $none();

    // A normal weekend.
    DailySale::query()->delete();
    sold($this->shop, $this->variant, '2025-10-01', '2025-12-01', 4);
    $none();

    // A real rise, but already planned for (also as a season that repeats every year).
    DailySale::query()->delete();
    sold($this->shop, $this->variant, '2025-10-01', '2025-11-27', 4);
    sold($this->shop, $this->variant, '2025-11-28', '2025-12-01', 12);
    $event = SalesEvent::factory()->for($this->shop)->create(['starts_on' => '2026-11-25', 'ends_on' => '2026-11-28', 'multiplier' => 2]);
    $none();
    $event->update(['starts_on' => '2025-11-20', 'ends_on' => '2025-12-02', 'repeats_yearly' => true]);
    $none();
    $event->update(['repeats_yearly' => false]);
    expect($this->getJson('/api/sales-events/suggestions', $this->auth)->json('data'))->toHaveCount(1);
});

it('is silent once the peak is more than three months away', function () {
    sold($this->shop, $this->variant, '2025-10-01', '2025-11-27', 4);
    sold($this->shop, $this->variant, '2025-11-28', '2025-12-01', 12);
    $this->travelTo('2026-08-01 10:00:00');

    $this->getJson('/api/sales-events/suggestions', $this->auth)->assertOk()->assertExactJson(['data' => []]);
});

it('does not exist when sales events are switched off', function () {
    config(['features.sales_events' => false]);

    $this->getJson('/api/sales-events/suggestions', $this->auth)->assertNotFound();
});
