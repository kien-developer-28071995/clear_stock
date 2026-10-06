<?php

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Events\ShopInstalled;
use App\Jobs\PostShopEventToSlack;
use App\Models\Shop;
use App\Monitoring\SlackNotifier;
use App\Services\App\BillingService;
use App\Services\ShopLifecycleService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Install, uninstall, upgrade and downgrade messages go to a separate Slack channel.

const EVENTS_URL = 'https://hooks.slack.test/services/T/B/EVENTS';

beforeEach(function () {
    Queue::fake();
    config(['monitoring.events_slack_webhook_url' => EVENTS_URL, 'monitoring.environment' => 'production']);
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'name' => 'Demo Store', 'plan' => 'free', 'currency' => 'USD', 'installed_at' => now()->subDays(40)]);

    // Shopify's active subscriptions; tests change it with exchangeArray().
    $this->subscriptions = new ArrayObject;
    Http::fake(['*.myshopify.com/*' => fn (Request $r) => Http::response(['data' => ['currentAppInstallation' => ['activeSubscriptions' => $this->subscriptions->getArrayCopy()]]])]);
});

/** Text of the Slack messages queued so far. */
function eventMessages(): array
{
    return Queue::pushed(PostShopEventToSlack::class)->map(fn ($job) => json_encode($job->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->values()->all();
}

it('announces new installs and reinstalls', function () {
    ShopInstalled::dispatch($this->shop, false);
    ShopInstalled::dispatch($this->shop, true);

    expect(eventMessages())->toHaveCount(2)
        ->and(eventMessages()[0])->toContain('[production] :tada: New install')->toContain('Demo Store')->toContain('demo.myshopify.com')->toContain('Active installs')
        ->and(eventMessages()[1])->toContain(':recycle: Reinstall');
});

it('announces uninstalls with the plan the shop was on', function () {
    $this->shop->update(['plan' => 'growth', 'plan_interval' => 'annual']);

    app(ShopLifecycleService::class)->markUninstalled('demo.myshopify.com');
    app(ShopLifecycleService::class)->markUninstalled('demo.myshopify.com'); // webhook delivered twice

    expect(eventMessages())->toHaveCount(1)
        ->and(eventMessages()[0])->toContain(':wave: Uninstall')->toContain('Plan at uninstall')->toContain('Growth')->toContain('40 days');
});

it('announces upgrades and downgrades with the MRR change, once per change', function () {
    $this->subscriptions->exchangeArray([[
        'id' => 'gid://shopify/AppSubscription/1', 'name' => BillingService::subscriptionName(Plan::Growth, PlanInterval::Monthly),
        'status' => 'ACTIVE', 'currentPeriodEnd' => '2026-10-20T00:00:00Z', 'test' => true,
    ]]);
    app(BillingService::class)->refresh($this->shop->fresh());
    app(BillingService::class)->refresh($this->shop->fresh()); // the webhook after the return page: no change

    expect(eventMessages())->toHaveCount(1)
        ->and(eventMessages()[0])->toContain(':arrow_up: Upgrade')->toContain('Free')->toContain('Growth (monthly) · $6.00/mo')->toContain('+$6.00');

    $this->subscriptions->exchangeArray([]);
    app(BillingService::class)->refresh($this->shop->fresh());

    expect(eventMessages()[1])->toContain(':arrow_down: Downgrade')->toContain('-$6.00');
});

it('announces a switch to annual billing', function () {
    $this->shop->update(['plan' => 'starter', 'plan_interval' => 'monthly', 'subscription_id' => 'gid://shopify/AppSubscription/1']);
    $this->subscriptions->exchangeArray([[
        'id' => 'gid://shopify/AppSubscription/2', 'name' => BillingService::subscriptionName(Plan::Starter, PlanInterval::Annual),
        'status' => 'ACTIVE', 'currentPeriodEnd' => '2027-09-20T00:00:00Z', 'test' => true,
    ]]);

    app(BillingService::class)->refresh($this->shop->fresh());

    expect(eventMessages()[0])->toContain(':repeat: Billing interval changed')->toContain('Starter (annual) · $38.00/yr')->toContain('-$0.83'); // 38/12 - 4
});

it('stays silent without an events webhook', function () {
    config(['monitoring.events_slack_webhook_url' => null]);

    ShopInstalled::dispatch($this->shop, false);

    Queue::assertNotPushed(PostShopEventToSlack::class);
});

it('posts the message to the events channel and retries if Slack fails', function () {
    Http::fake([EVENTS_URL => Http::sequence()->push('nope', 500)->push('ok')]);
    $job = new PostShopEventToSlack(['text' => 'hi']);

    $job->withFakeQueueInteractions()->handle(app(SlackNotifier::class));
    $job->assertReleased();

    (new PostShopEventToSlack(['text' => 'hi']))->withFakeQueueInteractions()->handle(app(SlackNotifier::class));
    Http::assertSent(fn (Request $r) => $r->url() === EVENTS_URL && $r['text'] === 'hi');
    expect(Http::recorded(fn (Request $r) => $r->url() === EVENTS_URL))->toHaveCount(2);
});
