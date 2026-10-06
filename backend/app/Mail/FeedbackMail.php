<?php

namespace App\Mail;

use App\Models\Shop;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** What a merchant wrote in the app's feedback box, sent to the support address. Not stored. */
class FeedbackMail extends QueuedMailable
{
    public function __construct(public Shop $shop, public string $text, public ?string $replyEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::subjectLine('Feedback from '.($this->shop->name ?? $this->shop->domain)),
            replyTo: $this->replyEmail ? [new Address($this->replyEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.feedback', with: [
            'appName' => config('shopify.app_name'),
            'plan' => $this->shop->plan->value,
            'installed' => $this->shop->installed_at?->toDateString(),
            'language' => $this->shop->locale,
        ]);
    }
}
