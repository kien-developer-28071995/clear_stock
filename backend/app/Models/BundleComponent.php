<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shop_id
 * @property int $bundle_variant_id
 * @property int $component_variant_id
 * @property int $quantity
 * @property string $source shopify|manual
 */
class BundleComponent extends Model
{
    use BelongsToShop, HasFactory;

    public const SOURCE_SHOPIFY = 'shopify';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = ['shop_id', 'bundle_variant_id', 'component_variant_id', 'quantity', 'source'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'bundle_variant_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'component_variant_id');
    }
}
