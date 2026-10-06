<?php

namespace App\Mail;

use App\Models\Shop;
use App\Support\Website;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Syncing with Shopify keeps failing: forecasts are getting stale. Sent once per streak. */
class SyncFailingMail extends QueuedMailable
{
    public function __construct(public Shop $shop, public int $failures) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Action needed: '.config('shopify.app_name').' cannot sync '.($this->shop->name ?? $this->shop->domain));
    }

    public function content(): Content
    {
        $code = $this->shop->sync_error['code'] ?? 'unknown';

        return new Content(markdown: 'mail.sync-failing', with: [
            'appName' => config('shopify.app_name'),
            'appUrl' => "https://{$this->shop->domain}/admin/apps/".config('shopify.api_key').'/settings',
            'lastSynced' => $this->shop->last_synced_at?->setTimezone($this->shop->timezone)->format('M j, Y'),
            'reason' => match ($code) {
                'reauthorize' => 'Shopify no longer accepts the app\'s access. Opening the app once renews it.',
                'export_access_denied' => 'Shopify refused to export your orders to the app.',
                'timeout' => 'Shopify took too long to prepare your store data.',
                'export_failed', 'shopify_error' => 'Shopify returned an error while exporting your store data.',
                default => 'An unexpected error stopped the sync.',
            },
            'reauthorize' => $code === 'reauthorize',
            'supportUrl' => Website::url('support'),
        ]);
    }
}
