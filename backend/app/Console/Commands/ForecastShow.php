<?php

namespace App\Console\Commands;

use App\Models\Forecast;
use App\Models\Shop;
use App\Services\Forecast\ExplanationFormatter;
use Illuminate\Console\Command;

/** Developer/support tool: list forecasts soonest stock-out first, with explanations. */
class ForecastShow extends Command
{
    protected $signature = 'forecast:show {--shop= : Shop domain (default: first shop)} {--sku= : Only this SKU} {--limit=10}';

    protected $description = 'Show forecasts with their plain-language explanation';

    public function handle(ExplanationFormatter $formatter): int
    {
        $shop = $this->option('shop') ? Shop::firstWhere('domain', $this->option('shop')) : Shop::query()->first();
        if ($shop === null) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        $forecasts = Forecast::query()->forShop($shop)->whereNull('location_id')
            ->with('variant')
            ->when($this->option('sku'), fn ($q, $sku) => $q->whereHas('variant', fn ($v) => $v->where('sku', $sku)))
            ->orderByRaw('stockout_date IS NULL, stockout_date')
            ->orderByDesc('avg_daily_sales')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($forecasts->isEmpty()) {
            $this->warn('No forecasts yet. Run: php artisan forecast:run');

            return self::SUCCESS;
        }

        foreach ($forecasts as $f) {
            $this->newLine();
            $this->line("<options=bold>{$f->variant->displayName()}</>".($f->variant->sku ? " [{$f->variant->sku}]" : ''));
            $this->line(sprintf('  stock %d · %s/day · cover %s days · stock-out %s · reorder %s · order %d · %s',
                $f->current_stock, $f->avg_daily_sales, $f->days_of_cover ?? '∞', $f->stockout_date?->toDateString() ?? '–',
                $f->reorder_date?->toDateString() ?? '–', $f->suggested_qty, $f->confidence->value));
            foreach ($formatter->sentences($f->explanation) as $sentence) {
                $this->line("  • {$sentence}");
            }
        }

        return self::SUCCESS;
    }
}
