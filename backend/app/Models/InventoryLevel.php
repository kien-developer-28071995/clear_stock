<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int $variant_id
 * @property int $location_id
 * @property int $available
 * @property ?Carbon $shopify_updated_at last webhook applied (out-of-order guard)
 */
class InventoryLevel extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'variant_id', 'location_id', 'available', 'incoming', 'shopify_updated_at'];

    protected function casts(): array
    {
        return ['available' => 'integer', 'incoming' => 'integer', 'shopify_updated_at' => 'datetime'];
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
