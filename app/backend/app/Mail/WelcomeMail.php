<?php

namespace App\Mail;

use App\Models\Shop;
use App\Support\Website;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent once, right after a shop's first install: what happens next and where to start. */
class WelcomeMail extends QueuedMailable
{
    public function __construct(public Shop $shop) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::subjectLine('Welcome to '.config('shopify.app_name').': your first forecasts are on the way'),
            // Replies reach a person, not a no-reply box.
            replyTo: [new Address((string) config('shopify.support_email'))],
        );
    }

    public function content(): Content
    {
        $appUrl = "https://{$this->shop->domain}/admin/apps/".config('shopify.api_key');

        return new Content(markdown: 'mail.welcome', with: [
            'appName' => config('shopify.app_name'),
            'storeName' => $this->shop->name ?? $this->shop->domain,
            'appUrl' => $appUrl,
            'settingsUrl' => "{$appUrl}/settings",
            'leadTimeDays' => (int) $this->shop->default_lead_time_days,
            'supportUrl' => Website::url('support'),
        ]);
    }
}
