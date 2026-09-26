<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Support\Features;
use Illuminate\Console\Command;

/**
 * Shows the app-wide feature switches (config/features.php) as the app sees them, and
 * warns about combinations that would not work: a switch whose dependency is off, a
 * Shopify scope a switched-on feature needs, or a Growth plan with nothing Growth-only left.
 */
class FeaturesStatus extends Command
{
    protected $signature = 'features:status';

    protected $description = 'Show which features are switched on app-wide and check the configuration';

    /** Scopes a feature needs to work (a missing optional scope is requested in the app). */
    private const SCOPES = [
        'locations' => ['read_merchant_managed_fulfillment_orders', 'read_third_party_fulfillment_orders'],
    ];

    private const GROWTH_ONLY = [Feature::Locations, Feature::Transfers, Feature::RealtimeAlerts, Feature::SupplierAutoEmail, Feature::FlowTriggers];

    public function handle(): int
    {
        $switches = Features::all();
        $this->table(['Feature', 'On'], collect($switches)->map(fn ($on, $key) => [$key, $on ? 'yes' : 'NO'])->values()->all());

        $warnings = [];
        foreach (Features::inactiveBecauseOfDependencies() as $switch) {
            $this->line("<comment>Note:</comment> {$switch} is on but stays off: it needs a switch that is off.");
        }

        $scopes = array_map('trim', explode(',', (string) config('shopify.scopes')));
        foreach (self::SCOPES as $switch => $needed) {
            $missing = array_diff($needed, $scopes);
            if (($switches[$switch] ?? false) && $missing !== []) {
                $warnings[] = "{$switch} is on but SHOPIFY_SCOPES lacks ".implode(', ', $missing).' (also add them to shopify.app.toml).';
            }
            if (! ($switches[$switch] ?? true) && $missing === []) {
                $this->line("<comment>Note:</comment> {$switch} is off; its scopes (".implode(', ', $needed).') can be removed from SHOPIFY_SCOPES and shopify.app.toml.');
            }
        }

        $growthFeatures = array_filter(self::GROWTH_ONLY, fn (Feature $f) => Features::enabled($f));
        $offered = (bool) config('billing.plans.growth.offered', true);
        if ($offered && $growthFeatures === []) {
            $warnings[] = 'Growth is offered but every Growth-only feature is off: set BILLING_GROWTH_OFFERED=false.';
        }
        $this->line('Growth plan offered: '.($offered ? 'yes' : 'no').'; Growth-only features on: '.(count($growthFeatures) ?: 'none'));
        if (! ($switches['locations'] ?? true)) {
            $this->line('<comment>Note:</comment> after switching locations back on, run `php artisan sync:run --full --shop=...` for Growth shops (per-location sales are not collected while it is off).');
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return $warnings === [] ? self::SUCCESS : self::FAILURE;
    }
}
