<?php

use App\Jobs\SendWeeklySummary;
use App\Mail\WeeklySummaryMail;
use App\Models\AlertSetting;
use App\Models\Location;
use App\Models\Shop;
use App\Services\App\WeeklySummaryService;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-09-21 06:00:00'); // Monday, UTC
    // Free plan: the summary is for everyone.
    $this->shop = Shop::factory()->create(['plan' => 'free', 'timezone' => 'UTC', 'name' => 'Demo Store', 'currency' => 'USD', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['unit_cost' => 2, 'sku' => 'MUG-1']);   // reorder now
    $this->plate = product($this->shop, $this->location, 'Plate', stock: 5000, perDay: 1, attrs: ['unit_cost' => 3]);               // slow: 5000 x 3
    app(ForecastService::class)->runForShop($this->shop);
    $this->setting = AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test', 'enabled' => false, 'weekly_summary' => true, 'weekly_day' => 1]);
    $this->summary = app(WeeklySummaryService::class);
    $this->at = fn (string $utc) => CarbonImmutable::parse($utc, 'UTC');
});

it('sends one summary a week on the chosen weekday, on every plan', function () {
    expect($this->summary->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-21 08:05')))->toBe('sent');

    Mail::assertQueued(WeeklySummaryMail::class, function (WeeklySummaryMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('owner@demo.test')
            && $mail->envelope()->subject === 'Your stock this week: 1 product to reorder · Demo Store'
            && str_contains($html, 'Mug') && str_contains($html, 'MUG-1')
            && str_contains($html, '$15,000')        // slow stock at cost
            && str_contains($html, '$15,020')        // inventory value: 10 x 2 + 5000 x 3
            && str_contains($html, '/admin/apps/'.TEST_API_KEY.'/settings');
    });
    expect($this->setting->fresh()->weekly_summary_sent_at)->not->toBeNull();

    // Later the same day, and the next days: nothing more.
    expect($this->summary->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-21 12:00')))->toBe('already_sent_this_week')
        ->and($this->summary->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-22 09:00')))->toBe('not_this_weekday');
    Mail::assertQueuedCount(1);
});

it('respects the switch, the setting, the weekday, the hour and stale forecasts', function (array $shopAttrs, array $settingAttrs, string $at, string $reason) {
    $this->shop->update($shopAttrs);
    $this->setting->update($settingAttrs);

    expect($this->summary->sendIfDue($this->shop->fresh(), ($this->at)($at)))->toBe($reason);
    Mail::assertNothingQueued();
})->with([
    'switched off by the merchant' => [[], ['weekly_summary' => false], '2026-09-21 09:00', 'disabled'],
    'no email' => [[], ['email' => null], '2026-09-21 09:00', 'disabled'],
    'other weekday' => [[], ['weekly_day' => 3], '2026-09-21 09:00', 'not_this_weekday'],
    'before the send hour' => [[], [], '2026-09-21 07:00', 'too_early'],
    'uninstalled' => [['uninstalled_at' => '2026-09-20 00:00:00'], [], '2026-09-21 09:00', 'not_installed'],
    'stale forecast' => [['forecasted_at' => '2026-09-18 00:00:00'], [], '2026-09-21 09:00', 'stale_forecast'],
]);

it('is off for everyone when switched off app-wide', function () {
    config(['features.weekly_summary' => false]);
    expect($this->summary->sendIfDue($this->shop->fresh(), ($this->at)('2026-09-21 09:00')))->toBe('feature_off');

    Queue::fake();
    $this->artisan('alerts:send')->assertSuccessful();
    Queue::assertNotPushed(SendWeeklySummary::class);
});

it('is queued hourly for shops that opted in', function () {
    Queue::fake();
    $this->artisan('alerts:send')->assertSuccessful();
    Queue::assertPushed(SendWeeklySummary::class, fn ($job) => $job->shopId === $this->shop->id);
});

it('is saved from Settings', function () {
    $auth = ['Authorization' => 'Bearer '.sessionToken($this->shop->domain)];
    $this->setting->update(['weekly_summary' => false]);

    $this->putJson('/api/settings', ['alerts' => ['weekly_summary' => true, 'weekly_day' => 5, 'email' => 'me@demo.test']], $auth)
        ->assertOk()->assertJsonPath('data.alerts.weekly_summary', true)->assertJsonPath('data.alerts.weekly_day', 5);
    expect($this->setting->fresh()->weekly_summary)->toBeTrue();
});
