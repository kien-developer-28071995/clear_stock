<?php

namespace App\Mail;

use App\Enums\AlertFrequency;
use App\Models\Shop;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReorderDigestMail extends QueuedMailable
{
    /** @param array<int, array<string, mixed>> $items */
    public function __construct(
        public Shop $shop,
        public array $items,
        public int $totalCount,
        public int $newCount,
        public AlertFrequency $frequency,
    ) {}

    public function envelope(): Envelope
    {
        $what = $this->totalCount === 1 ? '1 product needs' : "{$this->totalCount} products need";
        // Products listed only for their few days of stock left are not due for an order yet.
        $lowCover = array_filter($this->items, fn ($i) => ($i['low_cover_days'] ?? null) !== null) !== [];

        return new Envelope(subject: self::subjectLine("{$what} ".($lowCover ? 'attention' : 'reordering').' · '.($this->shop->name ?? $this->shop->domain)));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reorder-digest', with: [
            'appName' => config('shopify.app_name'),
            'appUrl' => "https://{$this->shop->domain}/admin/apps/".config('shopify.api_key'),
            'settingsUrl' => "https://{$this->shop->domain}/admin/apps/".config('shopify.api_key').'/settings',
            'more' => max(0, $this->totalCount - count($this->items)),
        ]);
    }
}
