<?php

namespace App\Models;

use App\Enums\AlertFrequency;
use App\Enums\RealtimeAlertMode;
use App\Models\Concerns\BelongsToShop;
use App\Observers\AlertSettingObserver;
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
 */
#[ObservedBy(AlertSettingObserver::class)]
class AlertSetting extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'email', 'enabled', 'frequency', 'weekly_day', 'realtime'];

    protected $attributes = ['enabled' => true, 'frequency' => 'daily', 'weekly_day' => 1, 'realtime' => 'off'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'frequency' => AlertFrequency::class,
            'weekly_day' => 'integer',
            'realtime' => RealtimeAlertMode::class,
        ];
    }
}
