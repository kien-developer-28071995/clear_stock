<?php

namespace App\Models;

use App\Enums\Confidence;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int $variant_id
 * @property ?int $location_id
 * @property int $current_stock
 * @property int $target_stock order-up-to level
 * @property int $excess_units stock + on the way above target_stock
 * @property string $lost_units_30d units that would have sold on the out-of-stock days of the last 30 days
 * @property int $incoming_stock on the way (Shopify "incoming"), counted toward reordering
 * @property string $avg_daily_sales
 * @property ?string $days_of_cover
 * @property ?Carbon $stockout_date
 * @property ?Carbon $reorder_date
 * @property int $reorder_point
 * @property int $suggested_qty
 * @property Confidence $confidence
 * @property array $explanation
 * @property Carbon $computed_at
 */
class Forecast extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id', 'variant_id', 'location_id', 'current_stock', 'incoming_stock', 'avg_daily_sales', 'days_of_cover',
        'stockout_date', 'reorder_date', 'reorder_point', 'suggested_qty', 'target_stock', 'excess_units', 'lost_units_30d', 'confidence', 'explanation', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'integer',
            'target_stock' => 'integer',
            'excess_units' => 'integer',
            'lost_units_30d' => 'decimal:2',
            'incoming_stock' => 'integer',
            'avg_daily_sales' => 'decimal:3',
            'days_of_cover' => 'decimal:1',
            'stockout_date' => 'date',
            'reorder_date' => 'date',
            'reorder_point' => 'integer',
            'suggested_qty' => 'integer',
            'confidence' => Confidence::class,
            'explanation' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
