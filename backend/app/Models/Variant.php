<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int $shopify_variant_id
 * @property int $shopify_product_id
 * @property ?int $inventory_item_id
 * @property string $product_title
 * @property ?string $title
 * @property ?string $sku
 * @property ?string $unit_cost
 * @property bool $tracked
 * @property bool $is_active
 * @property ?int $supplier_id
 * @property ?int $lead_time_override
 * @property ?int $safety_days
 * @property bool $is_bundle
 * @property ?Carbon $shopify_created_at
 */
class Variant extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id', 'shopify_variant_id', 'shopify_product_id', 'inventory_item_id',
        'product_title', 'title', 'sku', 'unit_cost', 'tracked', 'is_active',
        'supplier_id', 'lead_time_override', 'safety_days', 'is_bundle', 'shopify_created_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_variant_id' => 'integer',
            'shopify_product_id' => 'integer',
            'inventory_item_id' => 'integer',
            'unit_cost' => 'decimal:4',
            'tracked' => 'boolean',
            'is_active' => 'boolean',
            'lead_time_override' => 'integer',
            'safety_days' => 'integer',
            'is_bundle' => 'boolean',
            'shopify_created_at' => 'datetime',
        ];
    }

    /** "Product - Variant" for display; omits Shopify's "Default Title". */
    public function displayName(): string
    {
        return $this->title && $this->title !== 'Default Title'
            ? "{$this->product_title} - {$this->title}"
            : $this->product_title;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function dailySales(): HasMany
    {
        return $this->hasMany(DailySale::class);
    }

    /** Combined (all locations) forecast. */
    public function forecast(): HasOne
    {
        return $this->hasOne(Forecast::class)->whereNull('location_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ForecastOverride::class);
    }

    /** Components of this variant when it is a bundle. */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'bundle_variant_id');
    }

    /** Bundles this variant is a component of. */
    public function usedInBundles(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'component_variant_id');
    }
}
