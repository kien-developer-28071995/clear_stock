<?php

namespace App\Services\App;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Saved product list views (every plan): a name and the filters it stands for. Saving a name again replaces it. */
class SavedViewService
{
    private const MAX = 20;

    /** Filters a view may hold (the list's own; a page number or search text is not part of a view). */
    public const FILTERS = ['status', 'sort', 'vendor', 'product_type', 'abc', 'trend', 'location_id'];

    /** @return array<int, array{id: int, name: string, filters: array<string, string>}> */
    public function list(Shop $shop): array
    {
        return DB::table('saved_views')->where('shop_id', $shop->id)->orderBy('name')->get(['id', 'name', 'filters'])
            ->map(fn ($v) => ['id' => (int) $v->id, 'name' => $v->name, 'filters' => (array) json_decode((string) $v->filters, true)])->all();
    }

    /** @param array<string, mixed> $filters */
    public function save(Shop $shop, string $name, array $filters): array
    {
        $filters = array_map('strval', array_filter(array_intersect_key($filters, array_flip(self::FILTERS)), fn ($v) => $v !== null && $v !== ''));
        $exists = DB::table('saved_views')->where('shop_id', $shop->id)->where('name', $name)->exists();
        if (! $exists && DB::table('saved_views')->where('shop_id', $shop->id)->count() >= self::MAX) {
            throw ValidationException::withMessages(['name' => 'too_many_views']);
        }
        DB::table('saved_views')->upsert(
            [['shop_id' => $shop->id, 'name' => $name, 'filters' => json_encode($filters), 'created_at' => now(), 'updated_at' => now()]],
            ['shop_id', 'name'], ['filters', 'updated_at'],
        );

        return $this->list($shop);
    }

    public function delete(Shop $shop, int $id): array
    {
        DB::table('saved_views')->where('shop_id', $shop->id)->where('id', $id)->delete();

        return $this->list($shop);
    }
}
