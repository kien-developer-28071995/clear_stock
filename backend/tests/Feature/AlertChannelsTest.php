<?php

use App\Jobs\PostAlertDigestToSlack;
use App\Mail\ReorderDigestMail;
use App\Models\AlertLog;
use App\Models\AlertSetting;
use App\Models\Location;
use App\Models\Shop;
use App\Monitoring\SlackNotifier;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\AlertService;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/abcDEF123';

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    $this->travelTo('2026-09-20 06:00:00');
    $this->shop = Shop::factory()->create(['plan' => 'starter', 'timezone' => 'UTC', 'name' => 'Demo Store', 'domain' => 'demo.myshopify.com', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->mug = product($this->shop, $this->location, 'Mug *Blue*', stock: 10, perDay: 4);   // reorder now
    $this->cup = product($this->shop, $this->location, 'Cup', stock: 120, perDay: 4);         // 30 days left, reorder in 9 days
    $this->plate = product($this->shop, $this->location, 'Plate', stock: 5000, perDay: 1);    // fine
    app(ForecastService::class)->runForShop($this->shop);
    $this->setting = AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test']);
    $this->alerts = app(AlertService::class);
    $this->now = CarbonImmutable::parse('2026-09-20 08:05', 'UTC');
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

it('posts the digest to Slack as well, without the webhook in the queue payload', function () {
    $this->setting->update(['slack_webhook_url' => SLACK_URL]);
    expect(DB::table('alert_settings')->value('slack_webhook_url'))->not->toContain('hooks.slack.com'); // encrypted at rest

    expect($this->alerts->sendIfDue($this->shop->fresh(), $this->now))->toBe('sent');
    Mail::assertQueued(ReorderDigestMail::class);
    Queue::assertPushed(PostAlertDigestToSlack::class, function (PostAlertDigestToSlack $job) {
        $text = $job->payload['blocks'][1]['text']['text'];

        return $job->shopId === $this->shop->id
            && ! str_contains(json_encode($job->payload), 'hooks.slack.com')
            && str_contains($job->payload['text'], '1 product needs attention · Demo Store')
            && str_contains($text, '*Mug Blue*')       // no stray Slack formatting from the product name
            && str_contains($text, 'order *');
    });
});

it('sends to Slack alone when there is no email', function () {
    $this->setting->update(['email' => null, 'slack_webhook_url' => SLACK_URL]);

    expect($this->alerts->sendIfDue($this->shop->fresh(), $this->now))->toBe('sent');
    Mail::assertNothingQueued();
    Queue::assertPushed(PostAlertDigestToSlack::class);

    $this->setting->update(['slack_webhook_url' => null]);
    expect($this->alerts->sendIfDue($this->shop->fresh(), $this->now->addDay()))->toBe('disabled');
});

it('delivers to the stored webhook and retries when Slack fails', function () {
    $this->setting->update(['slack_webhook_url' => SLACK_URL]);
    Http::fake([SLACK_URL => Http::sequence()->push('ok')->push('no', 500)]);
    $job = new PostAlertDigestToSlack($this->shop->id, ['text' => 'hi']);

    $job->handle(app(SlackNotifier::class), app(ShopRepositoryInterface::class), app(AlertSettingRepositoryInterface::class));
    Http::assertSent(fn ($request) => $request->url() === SLACK_URL && $request['text'] === 'hi');
});

it('only accepts Slack webhook URLs', function () {
    $this->putJson('/api/settings', ['alerts' => ['slack_webhook_url' => 'https://evil.example/hook']], $this->auth)->assertStatus(422);
    $this->putJson('/api/settings', ['alerts' => ['slack_webhook_url' => 'http://hooks.slack.com/services/T/B/x']], $this->auth)->assertStatus(422);
    $this->putJson('/api/settings', ['alerts' => ['slack_webhook_url' => SLACK_URL]], $this->auth)->assertOk()->assertJsonPath('data.alerts.slack_webhook_url', SLACK_URL);
    $this->putJson('/api/settings', ['alerts' => ['slack_webhook_url' => null]], $this->auth)->assertOk()->assertJsonPath('data.alerts.slack_webhook_url', null);
});

it('also alerts on the merchant\'s days-of-stock threshold, once, and again when it gets worse', function () {
    $this->putJson('/api/settings', ['alerts' => ['cover_days' => 35]], $this->auth)->assertOk()->assertJsonPath('data.alerts.cover_days', 35);

    expect($this->alerts->sendIfDue($this->shop->fresh(), $this->now))->toBe('sent');
    Mail::assertQueued(ReorderDigestMail::class, function (ReorderDigestMail $mail) {
        $cup = collect($mail->items)->firstWhere('name', 'Cup');

        return $mail->totalCount === 2 && $cup['low_cover_days'] === 30
            && $mail->envelope()->subject === '2 products need attention · Demo Store'
            && str_contains($mail->render(), '(30 days left)');
    });
    expect(AlertLog::where('variant_id', $this->cup->id)->value('type')->value)->toBe('low_cover');

    // Next day: nothing new. Two days later the cup reaches its reorder point: reported again
    // (worse than before), although the 7-day cooldown has not passed.
    expect($this->alerts->sendIfDue($this->shop->fresh(), $this->now->addDay()))->toBe('nothing_new');
    $this->travelTo('2026-09-22 06:00:00');
    DB::table('inventory_levels')->where('variant_id', $this->cup->id)->update(['available' => 60]);
    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect($this->alerts->sendIfDue($this->shop->fresh(), CarbonImmutable::parse('2026-09-22 08:05', 'UTC')))->toBe('sent');
    expect(AlertLog::where('variant_id', $this->cup->id)->latest('id')->value('type')->value)->toBe('reorder_needed');
});

it('leaves products above the threshold out', function () {
    $this->setting->update(['cover_days' => 20]);
    expect($this->alerts->sendIfDue($this->shop->fresh(), $this->now))->toBe('sent');
    Mail::assertQueued(ReorderDigestMail::class, fn (ReorderDigestMail $mail) => $mail->totalCount === 1 && $mail->envelope()->subject === '1 product needs reordering · Demo Store');
});
