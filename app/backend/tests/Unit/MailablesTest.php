<?php

use App\Enums\AlertFrequency;
use App\Mail\QueuedMailable;
use App\Mail\ReorderDigestMail;
use App\Models\Shop;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('makes every email a QueuedMailable (mail queue, retries, logging)', function () {
    $mailables = collect(glob(app_path('Mail/*.php')))
        ->map(fn ($file) => 'App\\Mail\\'.basename($file, '.php'))
        ->reject(fn ($class) => $class === QueuedMailable::class);

    expect($mailables)->not->toBeEmpty();
    $mailables->each(fn ($class) => expect(is_subclass_of($class, QueuedMailable::class))->toBeTrue("{$class} must extend QueuedMailable"));
});

it('queues emails on the mail queue with 3 tries and backoff', function () {
    Queue::fake();
    $shop = Shop::factory()->make(['name' => 'Demo']);

    Mail::to('owner@demo.test')->queue(new ReorderDigestMail($shop, [], 1, 1, AlertFrequency::Daily));

    Queue::assertPushedOn('mail', SendQueuedMailable::class, fn (SendQueuedMailable $job) => $job->tries === 3 && $job->backoff() === [60, 300]);
});
