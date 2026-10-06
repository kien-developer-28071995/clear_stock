<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One email sent (or failed) by the app, whatever the feature. Metadata only.
 *
 * @property int $id
 * @property ?int $shop_id
 * @property string $mailable
 * @property string $status sent | failed
 * @property ?string $subject
 * @property ?string $from
 * @property array<int, string> $to
 * @property ?array<int, string> $cc
 * @property ?array<int, string> $bcc
 * @property ?array<int, string> $reply_to
 * @property ?array<int, string> $attachments
 * @property ?string $message_id
 * @property ?string $error
 * @property Carbon $created_at
 */
class EmailLog extends Model
{
    use MassPrunable;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const UPDATED_AT = null;

    protected $fillable = ['shop_id', 'mailable', 'status', 'subject', 'from', 'to', 'cc', 'bcc', 'reply_to', 'attachments', 'message_id', 'error'];

    protected function casts(): array
    {
        return ['to' => 'array', 'cc' => 'array', 'bcc' => 'array', 'reply_to' => 'array', 'attachments' => 'array'];
    }

    /** Kept for `mail_log.retention_days` (php artisan model:prune, scheduled daily). */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('mail_log.retention_days')));
    }
}
