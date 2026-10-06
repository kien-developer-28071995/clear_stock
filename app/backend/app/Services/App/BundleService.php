<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\Entitlements;
use App\Support\Gid;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Merchant-defined bundles: "selling 1 of X uses N of Y". Native Shopify
 * bundles are imported by the sync and are read-only here.
 */
class BundleService
{
    public function __construct(private readonly VariantRepositoryInterface $variants) {}

    public function list(Shop $shop): Collection
    {
        return $this->variants->bundles($shop);
    }

    /**
     * Variants can be given as local ids or Shopify variant gids (from the App Bridge resource picker).
     *
     * @param  array<int, array{variant: int|string, quantity: int}>  $components
     */
    public function save(Shop $shop, int|string $bundle, array $components): Variant
    {
        Entitlements::for($shop)->require(Feature::Bundles);

        $refs = array_merge([$bundle], array_column($components, 'variant'));
        $resolved = $this->resolve($shop, $refs);

        $bundleVariant = $resolved[(string) $bundle] ?? throw ValidationException::withMessages(['bundle' => 'product_not_synced']);

        $rows = [];
        foreach ($components as $i => $c) {
            $component = $resolved[(string) $c['variant']] ?? throw ValidationException::withMessages(["components.{$i}.variant" => 'product_not_synced']);
            if ($component->id === $bundleVariant->id) {
                throw ValidationException::withMessages(["components.{$i}.variant" => 'bundle_contains_itself']);
            }
            $rows[$component->id] = ['component_variant_id' => $component->id, 'quantity' => (int) $c['quantity']];
        }

        $this->variants->replaceManualComponents($bundleVariant, array_values($rows));
        RecomputeForecasts::dispatch($shop->id);

        return $this->variants->find($shop, $bundleVariant->id)->load('bundleComponents.component');
    }

    public function delete(Shop $shop, Variant $bundle): void
    {
        $this->variants->replaceManualComponents($bundle, []);
        RecomputeForecasts::dispatch($shop->id);
    }

    /** @return array<string, Variant> ref => variant */
    private function resolve(Shop $shop, array $refs): array
    {
        $out = [];
        $gids = array_filter($refs, fn ($r) => is_string($r) && str_starts_with($r, 'gid://'));
        $byShopifyId = $this->variants->findByShopifyIds($shop, array_map(fn ($g) => Gid::id($g), $gids));

        foreach ($refs as $ref) {
            $variant = is_string($ref) && str_starts_with($ref, 'gid://')
                ? $byShopifyId[Gid::id($ref)] ?? null
                : $this->variants->find($shop, (int) $ref);
            if ($variant !== null) {
                $out[(string) $ref] = $variant;
            }
        }

        return $out;
    }
}
