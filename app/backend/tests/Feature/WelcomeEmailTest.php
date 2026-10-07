<?php

use App\Events\ShopInstalled;
use App\Jobs\SendWelcomeEmail;
use App\Mail\WelcomeMail;
use App\Models\Shop;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Mail::fake();
    $this->shop = Shop::factory()->create(['name' => 'Demo Store', 'default_lead_time_days' => 21, 'access_token_expires_at' => now()->addYear()]);
    // The store's contact email as Shopify returned it (OnboardingService caches it).
    Cache::put(CacheKeys::shopContactEmail($this->shop->id), 'owner@demo.test', 600);
});

it('queues the welcome email on a first install only', function () {
    Queue::fake();

    ShopInstalled::dispatch($this->shop, true);
    Queue::assertNotPushed(SendWelcomeEmail::class);

    ShopInstalled::dispatch($this->shop, false);
    Queue::assertPushed(SendWelcomeEmail::class, fn (SendWelcomeEmail $job) => $job->shopId === $this->shop->id);
});

it('sends nothing when the switch is off', function () {
    Queue::fake();
    config(['features.welcome_email' => false]);

    ShopInstalled::dispatch($this->shop, false);

    Queue::assertNotPushed(SendWelcomeEmail::class);
});

it('emails the store contact, with the shop\'s lead time and links into the app', function () {
    SendWelcomeEmail::dispatchSync($this->shop->id);

    Mail::assertQueued(WelcomeMail::class, function (WelcomeMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('owner@demo.test')
            && $mail->envelope()->subject === 'Welcome to '.config('shopify.app_name').': your first forecasts are on the way'
            && $mail->envelope()->hasReplyTo(config('shopify.support_email'))
            && str_contains($html, 'Demo Store')
            && str_contains($html, '21 days')
            && str_contains($html, "https://{$this->shop->domain}/admin/apps/".TEST_API_KEY.'/settings');
    });
    Mail::assertQueuedCount(1);
});

it('sends nothing without a contact email or after an uninstall', function (Closure $arrange) {
    $arrange($this->shop);

    SendWelcomeEmail::dispatchSync($this->shop->id);

    Mail::assertNothingQueued();
})->with([
    'no contact email' => [fn (Shop $shop) => Cache::put(CacheKeys::shopContactEmail($shop->id), '', 600)],
    'uninstalled' => [fn (Shop $shop) => $shop->update(['uninstalled_at' => now(), 'access_token' => null])],
]);
