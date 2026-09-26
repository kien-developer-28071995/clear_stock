<?php

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Jobs\ReconcileBilling;
use App\Jobs\SyncRealtimeWebhook;
use App\Jobs\Webhooks\HandleAppSubscriptionUpdate;
use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\BillingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'plan' => 'free']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

/** @param array|ArrayObject $active active subscriptions (an ArrayObject can be changed later in the test) */
function fakeBilling(array|ArrayObject $active = []): void
{
    Http::fake(function (Request $r) use ($active) {
        $active = (array) $active;
        $q = $r['query'] ?? '';

        return match (true) {
            str_contains($q, 'CreateSubscription') => Http::response(['data' => ['appSubscriptionCreate' => [
                'appSubscription' => ['id' => 'gid://shopify/AppSubscription/1', 'status' => 'PENDING'],
                'confirmationUrl' => 'https://demo.myshopify.com/admin/charges/confirm/1',
                'userErrors' => [],
            ]]]),
            str_contains($q, 'CancelSubscription') => Http::response(['data' => ['appSubscriptionCancel' => [
                'appSubscription' => ['id' => 'gid://shopify/AppSubscription/1', 'status' => 'CANCELLED'], 'userErrors' => [],
            ]]]),
            str_contains($q, 'ActiveSubscriptions') => Http::response(['data' => ['currentAppInstallation' => ['activeSubscriptions' => $active]]]),
            default => Http::response(['errors' => [['message' => 'unexpected']]]),
        };
    });
}

it('lists plans with flat monthly and annual prices', function () {
    Http::fake();

    $this->getJson('/api/billing', $this->auth)
        ->assertOk()
        ->assertJsonPath('data.plan', 'free')
        ->assertJsonPath('data.plans.1.key', 'starter')
        ->assertJsonPath('data.plans.1.prices', ['monthly' => 4, 'annual' => 38])
        ->assertJsonPath('data.plans.2.prices', ['monthly' => 6, 'annual' => 58]);
});

it('creates a Shopify subscription and returns the approval URL', function (string $plan, string $interval, float $price, string $shopifyInterval) {
    fakeBilling();

    $this->postJson('/api/billing', ['plan' => $plan, 'interval' => $interval], $this->auth)
        ->assertOk()
        ->assertJsonPath('data.confirmation_url', 'https://demo.myshopify.com/admin/charges/confirm/1');

    Http::assertSent(function (Request $r) use ($price, $shopifyInterval) {
        $v = $r['variables'];
        $pricing = $v['lineItems'][0]['plan']['appRecurringPricingDetails'] ?? null;

        return str_contains($r['query'], 'replacementBehavior: STANDARD')
            && $pricing['price'] == ['amount' => $price, 'currencyCode' => 'USD']
            && $pricing['interval'] === $shopifyInterval
            && $v['test'] === true
            && str_starts_with($v['returnUrl'], 'https://demo.myshopify.com/admin/apps/'.TEST_API_KEY.'/plans');
    });
    // Nothing changes until the merchant approves.
    expect($this->shop->fresh()->plan->value)->toBe('free');
})->with([
    'starter monthly' => ['starter', 'monthly', 4.0, 'EVERY_30_DAYS'],
    'starter annual' => ['starter', 'annual', 38.0, 'ANNUAL'],
    'growth monthly' => ['growth', 'monthly', 6.0, 'EVERY_30_DAYS'],
    'growth annual' => ['growth', 'annual', 58.0, 'ANNUAL'],
]);

it('uses test charges on development stores only when test mode is off', function (bool $devStore, bool $expectTest) {
    config(['billing.test' => false]);
    Http::fake(fn (Request $r) => match (true) {
        str_contains($r['query'], 'ShopPlan') => Http::response(['data' => ['shop' => ['plan' => ['partnerDevelopment' => $devStore]]]]),
        default => Http::response(['data' => ['appSubscriptionCreate' => [
            'appSubscription' => ['id' => 'gid://shopify/AppSubscription/1', 'status' => 'PENDING'],
            'confirmationUrl' => 'https://demo.myshopify.com/admin/charges/confirm/1', 'userErrors' => [],
        ]]]),
    });

    $this->postJson('/api/billing', ['plan' => 'starter', 'interval' => 'monthly'], $this->auth)->assertOk();

    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'CreateSubscription') && $r['variables']['test'] === $expectTest);
})->with([
    'development store' => [true, true],
    'real store' => [false, false],
]);

it('activates the plan after approval by re-reading the subscription', function () {
    fakeBilling([['id' => 'gid://shopify/AppSubscription/7', 'name' => BillingService::subscriptionName(Plan::Growth, PlanInterval::Annual), 'status' => 'ACTIVE', 'test' => true, 'currentPeriodEnd' => '2027-09-20T00:00:00Z']]);

    $this->getJson('/api/billing?refresh=1', $this->auth)
        ->assertOk()
        ->assertJsonPath('data.plan', 'growth')
        ->assertJsonPath('data.interval', 'annual')
        ->assertJsonPath('data.entitlements.purchase_orders', true);

    expect($this->shop->fresh()->subscription_id)->toBe('gid://shopify/AppSubscription/7');
    Queue::assertPushed(RecomputeForecasts::class); // SKU limit / bundles changed
});

it('downgrades to Free by cancelling the subscription', function () {
    $this->shop->update(['plan' => 'starter', 'plan_interval' => 'monthly', 'subscription_id' => 'gid://shopify/AppSubscription/1', 'subscription_status' => 'ACTIVE']);
    fakeBilling();

    $this->postJson('/api/billing', ['plan' => 'free'], $this->auth)->assertOk()->assertJsonPath('data.confirmation_url', null);

    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'CancelSubscription') && $r['variables']['id'] === 'gid://shopify/AppSubscription/1');
    expect($this->shop->fresh())->plan->value->toBe('free')->subscription_id->toBeNull();
});

it('follows app_subscriptions/update webhooks (e.g. cancelled from the Shopify admin)', function () {
    $this->shop->update(['plan' => 'starter', 'subscription_id' => 'gid://shopify/AppSubscription/1']);
    fakeBilling([]); // no active subscription anymore

    postWebhook('app_subscriptions/update', ['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/1', 'status' => 'CANCELLED']])->assertOk();

    Queue::assertPushed(HandleAppSubscriptionUpdate::class, function ($job) {
        $job->handle(app(BillingService::class), app(ShopRepositoryInterface::class));

        return true;
    });
    expect($this->shop->fresh()->plan->value)->toBe('free');
});

it('grants a 7-day trial once per shop', function (?string $trialStartedAt, ?int $expectedTrialDays) {
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop->update(['trial_started_at' => $trialStartedAt]);
    fakeBilling();

    $this->postJson('/api/billing', ['plan' => 'starter', 'interval' => 'monthly'], $this->auth)->assertOk();

    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'CreateSubscription') && ($r['variables']['trialDays'] ?? null) === $expectedTrialDays);
})->with([
    'never subscribed' => [null, 7],
    'trial started 3 days ago' => ['2026-09-17 10:00:00', 4],
    'trial used up' => ['2026-09-01 10:00:00', null],
]);

it('starts the trial clock when the first paid plan activates, and never resets it', function () {
    $this->travelTo('2026-09-20 10:00:00');
    $active = new ArrayObject([['id' => 'gid://shopify/AppSubscription/7', 'name' => BillingService::subscriptionName(Plan::Starter, PlanInterval::Monthly), 'status' => 'ACTIVE', 'test' => true, 'currentPeriodEnd' => null]]);
    fakeBilling($active);

    $this->getJson('/api/billing?refresh=1', $this->auth)->assertJsonPath('data.trial_days_left', 7);
    expect($this->shop->fresh()->trial_started_at->toDateTimeString())->toBe('2026-09-20 10:00:00');

    // Cancel, come back 2 days later: 5 days left, same start date.
    $active->exchangeArray([]);
    $this->travelTo('2026-09-22 10:00:00');
    $this->getJson('/api/billing?refresh=1', $this->auth)->assertJsonPath('data.plan', 'free')->assertJsonPath('data.trial_days_left', 5);
    expect($this->shop->fresh()->trial_started_at->toDateTimeString())->toBe('2026-09-20 10:00:00');
});

it('parses only its own subscription names', function () {
    expect(BillingService::parseName('APP Starter (monthly)'))->toEqual([Plan::Starter, PlanInterval::Monthly])
        ->and(BillingService::parseName('Something else'))->toBeNull();
});

it('rejects unknown plans', function () {
    Http::fake();
    $this->postJson('/api/billing', ['plan' => 'enterprise'], $this->auth)->assertUnprocessable();
});

describe('daily reconciliation (missed webhooks)', function () {
    it('downgrades a shop whose subscription was cancelled without a webhook, and logs the drift', function () {
        Log::spy();
        $this->shop->update(['plan' => 'growth', 'plan_interval' => 'monthly', 'subscription_id' => 'gid://shopify/AppSubscription/1']);
        fakeBilling([]); // Shopify: nothing active

        expect(app(BillingService::class)->reconcile($this->shop->fresh()))->toBeTrue()
            ->and($this->shop->fresh()->plan)->toBe(Plan::Free);
        // The real-time inventory webhook of the lost Growth plan is removed too.
        Queue::assertPushed(SyncRealtimeWebhook::class, fn ($job) => $job->shopId === $this->shop->id);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Billing drift') && $ctx['from'] === 'growth (monthly)' && $ctx['to'] === 'free');
        Queue::assertPushed(RecomputeForecasts::class);
    });

    it('unlocks a plan that was paid while the app missed both the return page and the webhook', function () {
        fakeBilling([[
            'id' => 'gid://shopify/AppSubscription/9', 'name' => BillingService::subscriptionName(Plan::Starter, PlanInterval::Annual),
            'status' => 'ACTIVE', 'currentPeriodEnd' => '2027-09-20T00:00:00Z', 'test' => true,
        ]]);

        expect(app(BillingService::class)->reconcile($this->shop->fresh()))->toBeTrue()
            ->and($this->shop->fresh()->only(['plan', 'plan_interval', 'subscription_id']))->toBe([
                'plan' => Plan::Starter, 'plan_interval' => PlanInterval::Annual, 'subscription_id' => 'gid://shopify/AppSubscription/9',
            ]);
    });

    it('changes nothing when the app already agrees with Shopify', function () {
        fakeBilling([]);

        expect(app(BillingService::class)->reconcile($this->shop->fresh()))->toBeFalse();
        Queue::assertNotPushed(RecomputeForecasts::class);
    });

    it('checks every installed shop once a day, and skips shops without a usable token', function () {
        Shop::factory()->create(['uninstalled_at' => now()]);
        $gone = Shop::factory()->create(['access_token' => 'x']);
        $gone->forceFill(['access_token' => null])->save();

        $this->artisan('billing:reconcile')->assertSuccessful();

        Queue::assertPushed(ReconcileBilling::class, 1);
        Queue::assertPushed(ReconcileBilling::class, fn ($job) => $job->shopId === $this->shop->id);
    });
});
