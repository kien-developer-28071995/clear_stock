<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base of every email the app sends: queued on the dedicated `mail` queue (its own
 * Horizon supervisor, so heavy forecast work never delays email) and retried after
 * 1 and 5 minutes if the mail server hiccups. Sent and failed emails are logged
 * (App\Listeners\LogEmails). tests/Unit/MailablesTest.php checks all mailables use it.
 */
#[Queue('mail')]
#[Tries(3)]
#[Backoff(60, 300)]
abstract class QueuedMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * A subject built from names merchants type (store, product): one line of plain text, of a
     * length an inbox shows. Line breaks or control characters never reach an email header.
     */
    protected static function subjectLine(string $subject): string
    {
        return Str::limit(trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $subject)), 150);
    }
}
