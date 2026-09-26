<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;

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
}
