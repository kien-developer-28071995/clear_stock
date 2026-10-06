<?php

use App\Mail\SupplierOrderMail;
use App\Models\AlertSetting;
use App\Models\EmailLog;
use App\Models\Location;
use App\Models\Shop;
use App\Services\App\AlertService;
use App\Services\Forecast\ForecastService;
use App\Services\ShopLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Mail;

// Every email the app sends is logged (metadata, never the body), whatever sent it.

beforeEach(function () {
    $this->travelTo('2026-09-20 06:00:00');
    $this->shop = Shop::factory()->create(['plan' => 'growth', 'timezone' => 'UTC', 'name' => 'Demo Store', 'access_token_expires_at' => now()->addYear()]);
});

it('logs the reorder digest', function () {
    $location = Location::factory()->for($this->shop)->create();
    product($this->shop, $location, 'Mug', stock: 10, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
    AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test', 'frequency' => 'daily']);

    app(AlertService::class)->sendIfDue($this->shop->fresh(), CarbonImmutable::parse('2026-09-20 09:00', 'UTC'));

    $log = EmailLog::sole();
    expect($log->only(['shop_id', 'mailable', 'status', 'to', 'subject']))->toBe([
        'shop_id' => $this->shop->id,
        'mailable' => 'App\Mail\ReorderDigestMail',
        'status' => 'sent',
        'to' => ['owner@demo.test'],
        'subject' => '1 product needs reordering · Demo Store',
    ])->and($log->message_id)->not->toBeNull();
});

it('logs supplier emails with reply-to and attachment names', function () {
    Mail::to('orders@acme.test')->send(new SupplierOrderMail($this->shop, 'Acme', [['variant_id' => 1, 'sku' => 'MUG-1', 'name' => 'Mug', 'quantity' => 5]], null, 'owner@demo.test'));

    expect(EmailLog::sole()->only(['shop_id', 'mailable', 'to', 'reply_to', 'attachments']))->toBe([
        'shop_id' => $this->shop->id,
        'mailable' => SupplierOrderMail::class,
        'to' => ['orders@acme.test'],
        'reply_to' => ['owner@demo.test'],
        'attachments' => ['purchase-order-2026-09-20.csv'],
    ]);
});

it('logs any other email too, even without a shop', function () {
    Mail::raw('Hello', fn ($m) => $m->to('someone@example.test')->subject('Plain'));

    expect(EmailLog::sole()->only(['shop_id', 'mailable', 'subject', 'to']))->toBe(['shop_id' => null, 'mailable' => 'raw', 'subject' => 'Plain', 'to' => ['someone@example.test']]);
});

it('logs queued emails that failed for good, with the reason and no secrets', function () {
    $mailable = (new SupplierOrderMail($this->shop, 'Acme', [], null, null))->to('orders@acme.test');
    $job = Mockery::mock(Job::class)->shouldIgnoreMissing(); // other listeners (Horizon) read more of the job
    $job->shouldReceive('payload')->andReturn(['displayName' => SupplierOrderMail::class, 'data' => [
        'commandName' => SendQueuedMailable::class,
        'command' => serialize(new SendQueuedMailable($mailable)),
    ]]);

    event(new JobFailed('redis', $job, new RuntimeException('SMTP 550 rejected, token shpat_abc123')));

    expect(EmailLog::sole()->only(['shop_id', 'mailable', 'status', 'to', 'subject', 'error']))->toBe([
        'shop_id' => $this->shop->id,
        'mailable' => SupplierOrderMail::class,
        'status' => 'failed',
        'to' => ['orders@acme.test'],
        'subject' => 'Purchase order from Demo Store · 2026-09-20',
        'error' => 'RuntimeException: SMTP 550 rejected, token shpat_***',
    ]);
});

it('ignores other failed jobs', function () {
    $job = Mockery::mock(Job::class)->shouldIgnoreMissing(); // other listeners (Horizon) read more of the job
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\Jobs\Whatever', 'data' => ['commandName' => 'App\Jobs\Whatever', 'command' => '']]);

    event(new JobFailed('redis', $job, new RuntimeException('nope')));

    expect(EmailLog::count())->toBe(0);
});

it('prunes old logs and deletes a shop\'s logs with its data', function () {
    Mail::raw('Old', fn ($m) => $m->to('a@example.test'));
    $this->travel(181)->days();
    Mail::raw('New', fn ($m) => $m->to('b@example.test'));

    $this->artisan('model:prune', ['--model' => [EmailLog::class]])->assertSuccessful();
    expect(EmailLog::pluck('to')->all())->toBe([['b@example.test']]);

    Mail::to('x@example.test')->send(new SupplierOrderMail($this->shop, 'Acme', [], null, null));
    $this->shop->update(['uninstalled_at' => now(), 'access_token' => null]);
    app(ShopLifecycleService::class)->redactShop($this->shop->domain);
    expect(EmailLog::where('shop_id', $this->shop->id)->count())->toBe(0);
});
