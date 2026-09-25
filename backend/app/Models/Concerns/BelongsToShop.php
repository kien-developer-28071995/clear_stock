<?php

namespace App\Models\Concerns;

use App\Models\Shop;
use App\Support\ShopContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every shop-owned table has a shop_id. Inside an authenticated request
 * (ShopContext set) queries are scoped to that shop automatically and new rows
 * get its shop_id. Jobs/commands have no context and must scope explicitly
 * with ->forShop($shop).
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope('shop', function (Builder $query) {
            $context = app(ShopContext::class);
            if ($context->has()) {
                $query->where($query->qualifyColumn('shop_id'), $context->shop()->id);
            }
        });

        static::creating(function ($model) {
            $context = app(ShopContext::class);
            if ($model->shop_id === null && $context->has()) {
                $model->shop_id = $context->shop()->id;
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeForShop(Builder $query, Shop|int $shop): Builder
    {
        return $query->where($query->qualifyColumn('shop_id'), $shop instanceof Shop ? $shop->id : $shop);
    }
}
