<?php

namespace App\Mail;

use App\Models\Shop;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Real-time stock alert (Growth): products that just sold out or reached their reorder point. */
class RealtimeStockAlertMail extends QueuedMailable
{
    /** @param array<int, array<string, mixed>> $items */
    public function __construct(public Shop $shop, public array $items) {}

    public function envelope(): Envelope
    {
        $store = $this->shop->name ?? $this->shop->domain;
        $out = count(array_filter($this->items, fn ($i) => $i['out_of_stock']));

        if (count($this->items) === 1) {
            $item = $this->items[0];
            $subject = $item['name'].($item['out_of_stock'] ? ' just sold out' : ' is running low');
        } else {
            $subject = count($this->items).' products need attention'.($out > 0 ? " ({$out} sold out)" : '');
        }

        return new Envelope(subject: self::subjectLine("{$subject} · {$store}"));
    }

    public function content(): Content
    {
        $appUrl = "https://{$this->shop->domain}/admin/apps/".config('shopify.api_key');

        return new Content(markdown: 'mail.realtime-alert', with: [
            'appName' => config('shopify.app_name'),
            'appUrl' => $appUrl,
            'settingsUrl' => $appUrl.'/settings',
            'productUrl' => fn (int $variantId) => "{$appUrl}/products/{$variantId}",
            'maxPerDay' => (int) config('alerts.realtime.max_per_day'),
            'realertDays' => (int) config('alerts.realert_days'),
        ]);
    }
}
