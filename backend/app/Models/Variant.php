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
 * @property ?string $barcode
 * @property ?string $unit_cost
 * @property ?string $price current selling price (shop currency)
 * @property ?string $abc_class A, B or C by share of recent revenue; null without a price
 * @property string $revenue_90d net units sold in the ABC window x current price
 * @property string $revenue_share share of the shop's revenue in that window (0..1)
 * @property bool $tracked
 * @property bool $is_active
 * @property ?int $supplier_id
 * @property ?int $lead_time_override
 * @property ?int $safety_days
 * @property ?int $min_order_qty suggested orders are raised to at least this many units
 * @property ?int $pack_size suggested orders are rounded up to whole packs of this many units
 * @property ?string $vendor Shopify product vendor
 * @property ?string $product_type Shopify product type
 * @property ?int $min_stock manual reorder point (units, stock + on the way)
 * @property ?int $max_stock manual order-up-to level (units)
 * @property bool $alerts_muted no alert email mentions this product
 * @property bool $is_bundle
 * @property ?Carbon $shopify_created_at
 */
class Variant extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id', 'shopify_variant_id', 'shopify_product_id', 'inventory_item_id',
        'product_title', 'vendor', 'product_type', 'title', 'sku', 'barcode', 'unit_cost', 'price', 'tracked', 'is_active',
        'supplier_id', 'lead_time_override', 'safety_days', 'min_order_qty', 'pack_size', 'min_stock', 'max_stock', 'alerts_muted', 'is_bundle', 'shopify_created_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_variant_id' => 'integer',
            'shopify_product_id' => 'integer',
            'inventory_item_id' => 'integer',
            'unit_cost' => 'decimal:4',
            'price' => 'decimal:2',
            'revenue_90d' => 'decimal:2',
            'revenue_share' => 'decimal:6',
            'tracked' => 'boolean',
            'is_active' => 'boolean',
            'lead_time_override' => 'integer',
            'safety_days' => 'integer',
            'min_order_qty' => 'integer',
            'pack_size' => 'integer',
            'min_stock' => 'integer',
            'max_stock' => 'integer',
            'alerts_muted' => 'boolean',
            'is_bundle' => 'boolean',
            'shopify_created_at' => 'datetime',
        ];
    }

    /** Minimum order: the product's own, else its supplier's default (supplier must be loaded). */
    public function effectiveMinOrderQty(): ?int
    {
        return $this->min_order_qty ?? $this->supplier?->min_order_qty;
    }

    /** Units per case: the product's own, else its supplier's default (supplier must be loaded). */
    public function effectivePackSize(): ?int
    {
        return $this->pack_size ?? $this->supplier?->pack_size;
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
