<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property ?int $variant_id
 * @property AlertType $type
 * @property ?Carbon $stockout_date
 * @property Carbon $sent_at
 */
class AlertLog extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'variant_id', 'type', 'stockout_date', 'sent_at'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'stockout_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
