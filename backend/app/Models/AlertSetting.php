<?php

namespace App\Models;

use App\Enums\AlertFrequency;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $shop_id
 * @property ?string $email
 * @property bool $enabled
 * @property AlertFrequency $frequency
 * @property int $weekly_day
 */
class AlertSetting extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'email', 'enabled', 'frequency', 'weekly_day'];

    protected $attributes = ['enabled' => true, 'frequency' => 'daily', 'weekly_day' => 1];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'frequency' => AlertFrequency::class,
            'weekly_day' => 'integer',
        ];
    }
}
