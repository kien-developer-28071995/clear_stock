<?php

namespace App\Console\Commands\Dev;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DEV ONLY. Creates (or recreates) a made-up shop with thousands of products and months of
 * daily sales, to measure forecasts and API response times at a realistic size. Nothing here
 * talks to Shopify. Remove it again with --delete.
 */
class SeedLargeShop extends Command
{
    public const DOMAIN = 'perf-test.myshopify.com';

    protected $signature = 'dev:seed-large {--variants=10000} {--days=120} {--delete : Only remove the shop}';

    protected $description = '[DEV] Create a large made-up shop to measure performance';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        Shop::query()->where('domain', self::DOMAIN)->get()->each->delete(); // cascades to everything of the shop
        if ($this->option('delete')) {
            $this->info('Removed '.self::DOMAIN);

            return self::SUCCESS;
        }

        $variants = (int) $this->option('variants');
        $days = (int) $this->option('days');
        $now = now();
        $shop = Shop::query()->create([
            'domain' => self::DOMAIN, 'name' => 'Performance test', 'plan' => 'starter', 'currency' => 'USD', 'timezone' => 'UTC',
            'access_token' => 'dev-perf-token', 'access_token_expires_at' => $now->copy()->addYears(5), 'scopes' => 'read_products,read_inventory,read_locations,read_orders',
            'installed_at' => $now, 'onboarded_at' => $now, 'last_synced_at' => $now, 'sync_status' => 'completed',
        ]);
        $locationId = DB::table('locations')->insertGetId(['shop_id' => $shop->id, 'shopify_location_id' => 1, 'name' => 'Warehouse', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $supplierIds = [];
        for ($i = 1; $i <= 25; $i++) {
            $supplierIds[] = DB::table('suppliers')->insertGetId(['shop_id' => $shop->id, 'name' => "Supplier {$i}", 'lead_time_days' => 7 + $i, 'created_at' => $now, 'updated_at' => $now]);
        }

        mt_srand(42); // the same shop every time, so runs can be compared
        $bar = $this->output->createProgressBar($variants);
        $today = CarbonImmutable::now('UTC')->startOfDay();
        foreach (array_chunk(range(1, $variants), 500) as $chunk) {
            $rows = array_map(fn ($n) => [
                'shop_id' => $shop->id, 'shopify_variant_id' => 9_000_000_000 + $n, 'shopify_product_id' => 8_000_000_000 + intdiv($n, 4), 'inventory_item_id' => 7_000_000_000 + $n,
                'product_title' => 'Product '.intdiv($n, 4), 'title' => ['S', 'M', 'L', 'XL'][$n % 4], 'sku' => "PERF-{$n}", 'vendor' => 'Vendor '.($n % 40), 'product_type' => 'Type '.($n % 12),
                'unit_cost' => $cost = mt_rand(100, 4000) / 100, 'shopify_unit_cost' => $cost, 'price' => round($cost * 2.4, 2), 'tracked' => true, 'is_active' => true,
                'supplier_id' => $n % 3 === 0 ? null : $supplierIds[$n % 25], 'shopify_created_at' => $today->subDays(400), 'created_at' => $now, 'updated_at' => $now,
            ], $chunk);
            DB::table('variants')->insert($rows);
            $ids = DB::table('variants')->where('shop_id', $shop->id)->whereIn('shopify_variant_id', array_column($rows, 'shopify_variant_id'))->pluck('id')->all();

            $levels = [];
            $sales = [];
            foreach ($ids as $id) {
                $rate = mt_rand(0, 9) < 4 ? 0 : mt_rand(1, 60) / 10;      // 40% never sell
                $levels[] = ['shop_id' => $shop->id, 'variant_id' => $id, 'location_id' => $locationId, 'available' => mt_rand(0, 9) === 0 ? 0 : mt_rand(1, 400), 'created_at' => $now, 'updated_at' => $now];
                if ($rate > 0) {
                    for ($d = 1; $d <= $days; $d++) {
                        $units = (int) round($rate * mt_rand(40, 160) / 100);
                        if ($units > 0) {
                            $sales[] = ['shop_id' => $shop->id, 'variant_id' => $id, 'date' => $today->subDays($d)->toDateString(), 'units_sold' => $units, 'units_returned' => 0, 'was_in_stock' => true];
                        }
                    }
                }
            }
            DB::table('inventory_levels')->insert($levels);
            foreach (array_chunk($sales, 5000) as $part) {
                DB::table('daily_sales')->insert($part);
            }
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();
        $this->info(self::DOMAIN.": {$variants} variants, ".DB::table('daily_sales')->where('shop_id', $shop->id)->count().' daily sales rows.');
        $this->line('Next: php artisan forecast:run --shop='.self::DOMAIN.'  (then dev:session-token --shop='.self::DOMAIN.')');

        return self::SUCCESS;
    }
}
