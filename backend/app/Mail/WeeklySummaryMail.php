<?php

namespace App\Mail;

use App\Models\Shop;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Number;

/** Weekly summary: what to order, cash tied up, lost sales, inventory value. One email a week, opt-in. */
class WeeklySummaryMail extends QueuedMailable
{
    /** @param array<string, mixed> $summary see WeeklySummaryService::build() */
    public function __construct(public Shop $shop, public array $summary) {}

    public function envelope(): Envelope
    {
        $due = $this->summary['counts']['reorder_now'] ?? 0;
        $what = match (true) {
            $due === 0 => 'nothing to reorder',
            $due === 1 => '1 product to reorder',
            default => "{$due} products to reorder",
        };

        return new Envelope(subject: self::subjectLine("Your stock this week: {$what} · ".($this->shop->name ?? $this->shop->domain)));
    }

    public function content(): Content
    {
        $base = "https://{$this->shop->domain}/admin/apps/".config('shopify.api_key');
        $currency = $this->shop->currency;

        return new Content(markdown: 'mail.weekly-summary', with: [
            'appName' => config('shopify.app_name'),
            'appUrl' => $base,
            'settingsUrl' => $base.'/settings',
            'insightsUrl' => $base.'/insights',
            'money' => fn (float|int $amount) => $currency ? Number::currency($amount, $currency, 'en', 0) : Number::format($amount, 0),
        ]);
    }
}
