<?php

namespace App\Models;

use App\Enums\AlertFrequency;
use App\Enums\RealtimeAlertMode;
use App\Models\Concerns\BelongsToShop;
use App\Observers\AlertSettingObserver;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $shop_id
 * @property ?string $email
 * @property bool $enabled
 * @property AlertFrequency $frequency
 * @property int $weekly_day
 * @property RealtimeAlertMode $realtime
 * @property bool $weekly_summary one summary email a week (every plan, opt-in)
 * @property ?Carbon $weekly_summary_sent_at
 * @property ?string $slack_webhook_url Slack incoming webhook the digest is also posted to (encrypted)
 * @property ?int $cover_days also alert at this many days of stock left or fewer
 */
#[ObservedBy(AlertSettingObserver::class)]
class AlertSetting extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'email', 'enabled', 'frequency', 'weekly_day', 'realtime', 'weekly_summary', 'weekly_summary_sent_at', 'slack_webhook_url', 'cover_days'];

    protected $attributes = ['enabled' => true, 'frequency' => 'daily', 'weekly_day' => 1, 'realtime' => 'off'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'frequency' => AlertFrequency::class,
            'weekly_day' => 'integer',
            'realtime' => RealtimeAlertMode::class,
            'weekly_summary' => 'boolean',
            'weekly_summary_sent_at' => 'datetime',
            'slack_webhook_url' => 'encrypted',
            'cover_days' => 'integer',
        ];
    }

    /** The Slack webhook, or null when unset or unreadable (APP_KEY changed without APP_PREVIOUS_KEYS). */
    public function slackUrl(): ?string
    {
        try {
            return $this->slack_webhook_url ?: null;
        } catch (DecryptException) {
            return null;
        }
    }

    /** Somewhere to send the reorder digest: an email address or a Slack channel. */
    public function hasDestination(): bool
    {
        return (bool) $this->email || $this->getRawOriginal('slack_webhook_url') !== null;
    }
}
