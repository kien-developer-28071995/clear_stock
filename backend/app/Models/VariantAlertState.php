<?php

namespace App\Models;

use App\Enums\StockLevel;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Live stock level of a variant for real-time alerts.
 *
 * @property int $id
 * @property int $shop_id
 * @property int $variant_id
 * @property StockLevel $level
 * @property ?Carbon $pending_at worsened since the last real-time email, waiting to be sent
 */
class VariantAlertState extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'variant_id', 'level', 'pending_at'];

    protected $attributes = ['level' => 'ok'];

    protected function casts(): array
    {
        return ['level' => StockLevel::class, 'pending_at' => 'datetime'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
