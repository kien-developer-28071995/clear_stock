<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A draft inventory transfer created in Shopify from a transfer suggestion (Growth).
 *
 * @property int $id
 * @property int $shop_id
 * @property int $shopify_transfer_id
 * @property string $name
 * @property int $origin_location_id
 * @property int $destination_location_id
 * @property array<int, array{variant_id: int, quantity: int}> $items
 * @property int $total_units
 * @property Carbon $created_at
 */
class InventoryTransfer extends Model
{
    use BelongsToShop;

    public const UPDATED_AT = null;

    protected $fillable = ['shop_id', 'shopify_transfer_id', 'name', 'origin_location_id', 'destination_location_id', 'items', 'total_units'];

    protected function casts(): array
    {
        return ['items' => 'array', 'total_units' => 'integer', 'shopify_transfer_id' => 'integer'];
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }
}
