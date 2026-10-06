<?php

use App\Mail\FeedbackMail;
use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    config(['shopify.support_email' => 'help@clearstock.test']);
    // A shop the app has been useful to: installed a while ago, set up, synced and forecast.
    $this->shop = Shop::factory()->create([
        'domain' => 'demo.myshopify.com', 'name' => 'Demo', 'plan' => 'free', 'installed_at' => now()->subDays(10),
        'onboarded_at' => now()->subDays(10), 'sync_status' => 'completed', 'forecasted_at' => now()->subHour(),
    ]);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->prompt = fn () => $this->getJson('/api/shop', $this->auth)->assertOk()->json('data.review_prompt');
});

// --- Feedback box ---

it('emails feedback to the support address, with the reply address the merchant gave', function () {
    Mail::fake();

    $this->postJson('/api/feedback', ['message' => "  Love the explanations.\nPlease add PDF orders.  ", 'email' => 'owner@demo.test'], $this->auth)
        ->assertStatus(202)->assertJsonPath('data.sent', true);

    Mail::assertQueued(FeedbackMail::class, function (FeedbackMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('help@clearstock.test') && $mail->hasReplyTo('owner@demo.test') && $mail->hasSubject('Feedback from Demo')
            && $mail->text === "Love the explanations.\nPlease add PDF orders."
            && str_contains($html, 'demo.myshopify.com') && str_contains($html, 'Please add PDF orders.');
    });
});

it('shows what the merchant typed as text, never as HTML or Markdown', function () {
    $html = (new FeedbackMail($this->shop, '<script>alert(1)</script> [click](https://evil.test) <b>bold</b>', null))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<b>bold</b>')->not->toContain('href="https://evil.test"')
        ->toContain('&lt;script&gt;')->toContain('no address given');
});

it('refuses empty, tiny or huge feedback and a broken reply address', function (array $body, string $field) {
    Mail::fake();

    $this->postJson('/api/feedback', $body, $this->auth)->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
    Mail::assertNothingQueued();
})->with([
    'no message' => [['message' => ''], 'message'],
    'too short' => [['message' => 'hi'], 'message'],
    'too long' => [['message' => str_repeat('a', 2001)], 'message'],
    'not text' => [['message' => ['a' => 'b']], 'message'],
    'bad email' => [['message' => 'Looks good so far', 'email' => 'not-an-email'], 'email'],
    'header in email' => [['message' => 'Looks good so far', 'email' => "a@b.test\nBcc: x@y.test"], 'email'],
]);

it('lets a shop send only a few messages an hour', function () {
    Mail::fake();
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/feedback', ['message' => "Message number {$i}"], $this->auth)->assertStatus(202);
    }

    $this->postJson('/api/feedback', ['message' => 'One too many'], $this->auth)->assertStatus(429);
    Mail::assertQueuedCount(5);
});

// --- Asking for a review ---

it('may ask for a review only once the app has been useful', function (array $state) {
    expect(($this->prompt)())->toBeTrue();

    $this->shop->update($state);

    expect(($this->prompt)())->toBeFalse();
})->with([
    'installed this week' => [['installed_at' => '2026-09-16 10:00:00']],
    'not set up yet' => [['onboarded_at' => null]],
    'sync failed' => [['sync_status' => 'failed']],
    'told that syncing keeps failing' => [['sync_failure_notified_at' => '2026-09-19 10:00:00']],
    'no forecast yet' => [['forecasted_at' => null]],
    'asked before' => [['review_prompted_at' => '2026-01-01 10:00:00']],
]);

it('asks once: after the dialog was shown, or Shopify said it never will be, not again', function (string $code) {
    $this->postJson('/api/review-prompt', ['code' => $code], $this->auth)->assertNoContent();

    expect(($this->prompt)())->toBeFalse()
        ->and($this->shop->fresh()->review_prompt_result)->toBe($code)
        ->and($this->shop->fresh()->review_prompted_at->toDateTimeString())->toBe('2026-09-20 10:00:00');
})->with(['success', 'already-reviewed', 'merchant-ineligible']);

it('tries again later when Shopify did not show the dialog this time', function (string $code) {
    $this->postJson('/api/review-prompt', ['code' => $code], $this->auth)->assertNoContent();

    expect(($this->prompt)())->toBeTrue()->and($this->shop->fresh()->review_prompted_at)->toBeNull();
})->with(['cooldown-period', 'mobile-app', 'recently-installed', 'cancelled', 'annual-limit-reached']);

it('keeps the first answer and refuses an answer Shopify never gives', function () {
    $this->postJson('/api/review-prompt', ['code' => 'success'], $this->auth)->assertNoContent();
    $this->travelTo('2026-09-20 10:00:30');
    $this->postJson('/api/review-prompt', ['code' => 'already-reviewed'], $this->auth)->assertNoContent();
    $this->postJson('/api/review-prompt', ['code' => 'five-stars'], $this->auth)->assertStatus(422);
    $this->postJson('/api/review-prompt', ['code' => ['success']], $this->auth)->assertStatus(422);

    expect($this->shop->fresh()->review_prompt_result)->toBe('success');
});

it('never asks when the review prompt is switched off', function () {
    config(['features.review_prompt' => false]);

    expect(($this->prompt)())->toBeFalse();
    $this->postJson('/api/review-prompt', ['code' => 'success'], $this->auth)->assertStatus(404);
});
