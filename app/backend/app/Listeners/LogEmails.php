<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\Shop;
use App\Monitoring\ErrorAlert;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Logs every email the app sends, whatever feature sent it: `sent` when the mailer
 * delivered it to the transport, `failed` when a queued email gave up after its
 * retries. Only metadata is stored (who, what, when), never the body.
 * Logging must never break sending, so errors here are only reported.
 */
class LogEmails
{
    public function sent(MessageSent $event): void
    {
        try {
            $message = $event->sent->getOriginalMessage();
            if (! $message instanceof Email) {
                return;
            }
            $shop = $event->data['shop'] ?? null;

            EmailLog::query()->create([
                'shop_id' => $shop instanceof Shop ? $shop->id : null,
                'mailable' => $event->data['__laravel_mailable'] ?? 'raw',
                'status' => EmailLog::STATUS_SENT,
                'subject' => Str::limit((string) $message->getSubject(), 250),
                'from' => $this->addresses($message->getFrom())[0] ?? null,
                'to' => $this->addresses($message->getTo()),
                'cc' => $this->addresses($message->getCc()) ?: null,
                'bcc' => $this->addresses($message->getBcc()) ?: null,
                'reply_to' => $this->addresses($message->getReplyTo()) ?: null,
                'attachments' => array_map(fn ($a) => $a->getFilename() ?? $a->getName(), $message->getAttachments()) ?: null,
                'message_id' => $event->sent->getMessageId(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A queued email that failed for good (its exception was already reported to monitoring). */
    public function failed(JobFailed $event): void
    {
        $payload = $event->job->payload();
        if (($payload['data']['commandName'] ?? null) !== SendQueuedMailable::class) {
            return;
        }

        try {
            $mailable = null;
            try {
                $command = unserialize($payload['data']['command']);
                $mailable = $command instanceof SendQueuedMailable ? $command->mailable : null;
            } catch (Throwable) {
                // e.g. a model it carried was deleted meanwhile: log what we know
            }

            EmailLog::query()->create([
                'shop_id' => $this->shopOf($mailable),
                'mailable' => $mailable ? get_class($mailable) : (string) ($payload['displayName'] ?? 'unknown'),
                'status' => EmailLog::STATUS_FAILED,
                'subject' => $mailable ? Str::limit((string) $this->subjectOf($mailable), 250) : null,
                'to' => $mailable ? array_column($mailable->to, 'address') : [],
                'cc' => $mailable ? (array_column($mailable->cc, 'address') ?: null) : null,
                'bcc' => $mailable ? (array_column($mailable->bcc, 'address') ?: null) : null,
                'error' => Str::limit(ErrorAlert::mask(get_class($event->exception).': '.$event->exception->getMessage()), 1000),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not log a failed email', ['exception' => $e]);
        }
    }

    /** @param array<int, Address> $addresses @return array<int, string> */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $a) => $a->getAddress(), $addresses);
    }

    private function shopOf(?Mailable $mailable): ?int
    {
        $shop = $mailable && property_exists($mailable, 'shop') ? $mailable->shop : null;

        return $shop instanceof Shop ? $shop->id : null;
    }

    private function subjectOf(Mailable $mailable): ?string
    {
        return method_exists($mailable, 'envelope') ? $mailable->envelope()->subject : $mailable->subject;
    }
}
