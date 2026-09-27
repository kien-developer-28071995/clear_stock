<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An order placed outside Shopify, counted as stock on the way while open.
 *
 * @property int $id
 * @property int $shop_id
 * @property int $variant_id
 * @property ?int $supplier_id
 * @property int $quantity
 * @property Carbon $ordered_on
 * @property Carbon $expected_on
 * @property ?string $reference
 * @property string $source manual | supplier_email
 * @property string $status open | received | cancelled
 * @property ?Carbon $closed_at
 */
class ManualOrder extends Model
{
    use BelongsToShop, HasFactory;

    public const OPEN = 'open';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SUPPLIER_EMAIL = 'supplier_email';

    protected $fillable = ['shop_id', 'variant_id', 'supplier_id', 'quantity', 'ordered_on', 'expected_on', 'reference', 'source', 'status', 'closed_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'ordered_on' => 'date', 'expected_on' => 'date', 'closed_at' => 'datetime', 'supplier_id' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * open: counted as on the way; late: past its date but still counted (grace days);
     * overdue: past the grace days, no longer counted until the merchant updates it.
     */
    public function state(string $today): string
    {
        if ($this->status !== self::OPEN) {
            return $this->status;
        }
        $date = $this->expected_on->toDateString();

        return match (true) {
            $date >= $today => 'open',
            $date >= self::countedFrom($today) => 'late',
            default => 'overdue',
        };
    }

    /** Open orders expected on or after this date still count as on the way. */
    public static function countedFrom(string $today): string
    {
        return Carbon::parse($today)->subDays((int) config('forecast.manual_order_grace_days', 3))->toDateString();
    }
}
