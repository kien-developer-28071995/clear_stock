<?php

use App\Jobs\SendAlertDigest;
use App\Mail\ReorderDigestMail;
use App\Models\AlertLog;
use App\Models\AlertSetting;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Services\App\AlertService;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-09-20 06:00:00'); // UTC
    $this->shop = Shop::factory()->create(['plan' => 'starter', 'timezone' => 'UTC', 'name' => 'Demo Store', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);      // reorder now
    $this->plate = product($this->shop, $this->location, 'Plate', stock: 5000, perDay: 1); // fine
    app(ForecastService::class)->runForShop($this->shop);
    $this->setting = AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test', 'frequency' => 'daily']);
    $this->alerts = app(AlertService::class);
    $this->at = fn (string $utc) => CarbonImmutable::parse($utc, 'UTC');
});

it('sends one daily summary of what to reorder, with the reason', function () {
    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-20 08:05')))->toBe('sent');

    Mail::assertQueued(ReorderDigestMail::class, function (ReorderDigestMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('owner@demo.test')
            && $mail->totalCount === 1 && $mail->newCount === 1
            && $mail->envelope()->subject === '1 product needs reordering · Demo Store'
            && str_contains($html, 'Mug')
            && str_contains($html, 'Sells 4/day over the last 30 days.')
            && str_contains($html, '/admin/apps/'.TEST_API_KEY)
            && ! str_contains($html, 'Plate');
    });
    expect(AlertLog::where('type', 'reorder_needed')->where('variant_id', $this->mug->id)->exists())->toBeTrue();
});

it('respects plan, settings, send hour and weekday', function (array $shopAttrs, array $settingAttrs, string $at, string $reason) {
    $this->shop->update($shopAttrs);
    $this->setting->update($settingAttrs);

    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)($at)))->toBe($reason);
    Mail::assertNothingQueued();
})->with([
    'free plan' => [['plan' => 'free'], [], '2026-09-20 09:00', 'plan'],
    'turned off' => [[], ['enabled' => false], '2026-09-20 09:00', 'disabled'],
    'no email' => [[], ['email' => null], '2026-09-20 09:00', 'disabled'],
    'before 8am local' => [['timezone' => 'America/New_York'], [], '2026-09-20 10:00', 'too_early'], // 06:00 in NY
    'weekly, wrong day' => [[], ['frequency' => 'weekly', 'weekly_day' => 1], '2026-09-20 09:00', 'not_this_weekday'], // Sunday
    'stale forecast' => [['forecasted_at' => '2026-09-18 00:00:00'], [], '2026-09-20 09:00', 'stale_forecast'],
]);

it('sends weekly summaries on the chosen weekday', function () {
    $this->setting->update(['frequency' => 'weekly', 'weekly_day' => 7]); // Sunday

    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-20 09:00')))->toBe('sent');
});

it('never sends twice a day and stays quiet when nothing is new', function () {
    $this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-20 08:05'));

    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-20 15:00')))->toBe('already_sent_today');

    $this->travelTo('2026-09-21 06:00:00');
    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-21 09:00')))->toBe('nothing_new');
    Mail::assertQueuedCount(1);
});

it('mentions a product again after a week, or right away when it sells out', function () {
    $this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-20 08:05'));

    // Sold out the next day: escalation is news.
    InventoryLevel::where('variant_id', $this->mug->id)->update(['available' => 0]);
    $this->travelTo('2026-09-21 06:00:00');
    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-21 09:00')))->toBe('sent');

    // Still out of stock: quiet until the re-alert interval passes.
    $this->travelTo('2026-09-23 06:00:00');
    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-23 09:00')))->toBe('nothing_new');

    $this->travelTo('2026-09-29 06:00:00');
    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect($this->alerts->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-29 09:00')))->toBe('sent');
});

it('queues a check for every shop with alerts on', function () {
    Queue::fake();
    AlertSetting::factory()->create(['enabled' => false]);

    $this->artisan('alerts:send')->assertSuccessful();

    Queue::assertPushed(SendAlertDigest::class, 1);
    Queue::assertPushed(SendAlertDigest::class, fn ($job) => $job->shopId === $this->shop->id);
});
