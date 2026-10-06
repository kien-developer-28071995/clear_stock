<?php

namespace Tests\Feature;

use App\Models\DailyStat;
use App\Models\ShopRecord;
use App\Models\User;
use App\Reports\AppData;
use App\Reports\FeatureUsage;
use App\Reports\LedgerSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AppSchema;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use AppSchema, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppSchema();
        $this->travelTo('2026-10-01 08:00:00');
        $this->owner = User::factory()->create();
    }

    private function seedShops(): array
    {
        $mugs = $this->appShop(['domain' => 'mugs.myshopify.com', 'name' => 'Mugs & Co', 'plan' => 'starter', 'plan_interval' => 'monthly', 'installed_at' => '2026-09-22 10:00:00',
            'onboarded_at' => '2026-09-22 10:05:00', 'last_synced_at' => '2026-10-01 01:00:00', 'forecasted_at' => '2026-10-01 01:10:00', 'order_budget' => 500]);
        $tea = $this->appShop(['domain' => 'tea.myshopify.com', 'name' => 'Tea House', 'installed_at' => '2026-09-29 10:00:00', 'sync_status' => 'failed',
            'sync_error' => json_encode(['code' => 'reauthorize', 'params' => []]), 'onboarded_at' => '2026-09-29 10:05:00', 'forecasted_at' => '2026-09-29 10:10:00']);
        $left = $this->appShop(['domain' => 'left.myshopify.com', 'name' => 'Left Shop', 'installed_at' => '2026-09-15 10:00:00', 'uninstalled_at' => '2026-09-15 18:00:00']);
        $app = DB::connection('app');
        $app->table('suppliers')->insert([['shop_id' => $mugs, 'name' => 'Acme', 'auto_email' => false], ['shop_id' => $left, 'name' => 'Old', 'auto_email' => false]]);
        $app->table('variants')->insert([['shop_id' => $mugs, 'min_stock' => 5, 'is_active' => true], ['shop_id' => $tea, 'min_stock' => 5, 'is_active' => false]]);
        $app->table('alert_settings')->insert(['shop_id' => $mugs, 'email' => 'o@mugs.test', 'enabled' => true]);
        $app->table('manual_orders')->insert(['shop_id' => $tea, 'quantity' => 10]);
        $app->table('email_logs')->insert([
            ['shop_id' => $mugs, 'mailable' => 'App\\Mail\\ReorderDigestMail', 'status' => 'sent', 'created_at' => '2026-09-30 08:00:00'],
            ['shop_id' => $mugs, 'mailable' => 'App\\Mail\\ReorderDigestMail', 'status' => 'failed', 'created_at' => '2026-09-30 08:00:00'],
        ]);
        $app->table('sync_runs')->insert([['shop_id' => $tea, 'status' => 'failed', 'created_at' => '2026-09-30 01:00:00'], ['shop_id' => $mugs, 'status' => 'completed', 'created_at' => '2026-09-30 01:00:00']]);
        app(LedgerSync::class)->run();

        return compact('mugs', 'tea', 'left');
    }

    public function test_reports_need_a_login_and_there_is_no_sign_up(): void
    {
        foreach (['/', '/shops', '/shops/export', '/features', '/health'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/sync')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk()->assertSee('Sign in')
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_login_checks_the_password_and_locks_after_repeated_failures(): void
    {
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->owner);
        $this->post('/logout')->assertRedirect('/login');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $this->owner->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_ip_allowlist_hides_everything_from_other_addresses(): void
    {
        config(['report.allowed_ips' => ['10.0.0.0/8']]);
        $this->get('/login')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->actingAs($this->owner)->get('/')->assertNotFound();
    }

    public function test_the_owner_account_is_created_from_the_command_line(): void
    {
        $this->artisan('admin:user', ['email' => 'me@example.com', '--password' => 'short'])->assertFailed();
        $this->artisan('admin:user', ['email' => 'me@example.com', '--password' => 'a-long-passphrase'])->assertSuccessful();
        $this->post('/login', ['email' => 'me@example.com', 'password' => 'a-long-passphrase'])->assertRedirect('/');
    }

    public function test_overview_counts_installed_uninstalled_paying_and_weekly_movement(): void
    {
        $this->seedShops();

        $response = $this->actingAs($this->owner)->get('/')->assertOk();
        $data = $response->viewData('totals');
        $this->assertSame([3, 2, 1, 1, 4.0, 2], [$data['ever'], $data['installed'], $data['uninstalled'], $data['paying'], $data['mrr'], $data['onboarded']]);
        $this->assertSame(2, $data['active']); // both computed forecasts in the last 3 days
        $weeks = collect($response->viewData('weeks'))->keyBy('start');
        $this->assertSame(['installs' => 1, 'uninstalls' => 1], collect($weeks['2026-09-14'])->only(['installs', 'uninstalls'])->all());
        $this->assertSame(1, $weeks['2026-09-21']['installs']);
        $this->assertSame(1, $weeks['2026-09-28']['installs']);
        $this->assertSame(1, $response->viewData('stays')['same_day']);
        $response->assertSee('Mugs &amp; Co', false)->assertSee('Left Shop');
    }

    public function test_refresh_now_reads_the_app_again(): void
    {
        $this->seedShops();
        $this->appShop(['domain' => 'new.myshopify.com']);

        $this->actingAs($this->owner)->post('/sync')->assertRedirect()->assertSessionHas('status');
        $this->assertSame(4, ShopRecord::query()->count());
    }

    public function test_shops_can_be_filtered_by_status_plan_feature_and_name_and_exported(): void
    {
        $ids = $this->seedShops();
        $names = fn (string $query) => $this->actingAs($this->owner)->get('/shops?'.$query)->assertOk()->viewData('shops')->pluck('name')->all();

        $this->assertSame(['Tea House', 'Mugs & Co', 'Left Shop'], $names(''));
        $this->assertSame(['Left Shop'], $names('status=uninstalled'));
        $this->assertSame(['Mugs & Co'], $names('status=installed&plan=starter'));
        $this->assertSame(['Mugs & Co'], $names('feature=suppliers'));   // the uninstalled shop's supplier does not count
        $this->assertSame(['Tea House'], $names('feature=manual_orders'));
        $this->assertSame(['Tea House'], $names('q=tea'));
        $this->assertSame(['Left Shop', 'Mugs & Co', 'Tea House'], $names('sort=name'));

        $csv = $this->actingAs($this->owner)->get('/shops/export?status=installed')->assertOk()->streamedContent();
        $this->assertStringContainsString('Shop,Domain,Status,Plan', $csv);
        $this->assertStringContainsString('"Mugs & Co",mugs.myshopify.com,installed,starter,monthly,4', $csv);
        $this->assertStringContainsString('suppliers', $csv);
        $this->assertStringNotContainsString('Left Shop', $csv);

        // A shop name that a spreadsheet would run as a formula is neutralised.
        ShopRecord::query()->where('name', 'Mugs & Co')->update(['name' => '=HYPERLINK("http://evil.test","x")']);
        $this->assertStringContainsString('"\'=HYPERLINK(', $this->actingAs($this->owner)->get('/shops/export?status=installed')->streamedContent());
    }

    public function test_a_shop_page_shows_its_history_live_numbers_and_features(): void
    {
        $ids = $this->seedShops();
        $record = ShopRecord::query()->where('app_shop_id', $ids['mugs'])->first();

        $response = $this->actingAs($this->owner)->get("/shops/{$record->id}")->assertOk();
        $response->assertSee('mugs.myshopify.com')->assertSee('Installed')->assertSee('Order budget');
        $features = collect($response->viewData('features'))->flatten(1)->pluck('used', 'label');
        $this->assertTrue($features['Suppliers']);
        $this->assertTrue($features['Manual min / max stock']);
        $this->assertTrue($features['Reorder alert emails']);
        $this->assertFalse($features['Bundles']);
        $this->assertSame(1, $response->viewData('counts')['Suppliers']);

        $this->actingAs($this->owner)->get('/shops/999')->assertNotFound();
    }

    public function test_feature_usage_counts_installed_shops_per_feature_and_skips_what_the_app_lacks(): void
    {
        $this->seedShops();

        $usage = app(FeatureUsage::class)->all();
        $this->assertSame(1, count($usage['suppliers']['shop_ids']));
        $this->assertSame(1, count($usage['min_max']['shop_ids']));          // an inactive product does not count
        $this->assertSame(2, count($usage['onboarded']['shop_ids']));
        $this->assertFalse($usage['weekly_summary']['available']);           // column not in this version of the app
        $this->assertFalse($usage['forecast_profile']['available']);

        $response = $this->actingAs($this->owner)->get('/features')->assertOk()->assertSee('Not in the deployed version');
        $this->assertSame(2, $response->viewData('installed'));
        $this->assertSame(['1–2' => 1, '3–5' => 1], collect($response->viewData('depth'))->sortKeys()->all());

        // A newer app version adds the column: the feature shows up without a change here.
        Schema::connection('app')->table('alert_settings', fn (Blueprint $t) => $t->boolean('weekly_summary')->default(false));
        DB::connection('app')->table('alert_settings')->update(['weekly_summary' => true]);
        $this->app->forgetInstance(AppData::class);
        app(FeatureUsage::class)->forget();
        $fresh = (new FeatureUsage(new AppData))->all();
        $this->assertTrue($fresh['weekly_summary']['available']);
        $this->assertSame(1, count($fresh['weekly_summary']['shop_ids']));

        // Features that store nothing: counted from the app's feature_events, last 28 days only.
        $this->assertFalse($fresh['used_what_if']['available']);
        [$first, $second] = $fresh['onboarded']['shop_ids'];
        Schema::connection('app')->create('feature_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->string('feature');
            $t->date('day');
            $t->unsignedInteger('count');
        });
        DB::connection('app')->table('feature_events')->insert([
            ['shop_id' => $first, 'feature' => 'what_if', 'day' => now('UTC')->toDateString(), 'count' => 3],
            ['shop_id' => $first, 'feature' => 'what_if_export', 'day' => now('UTC')->subDay()->toDateString(), 'count' => 1],
            ['shop_id' => $second, 'feature' => 'what_if', 'day' => now('UTC')->subDays(40)->toDateString(), 'count' => 9],
            ['shop_id' => 999, 'feature' => 'purchase_plan', 'day' => now('UTC')->toDateString(), 'count' => 1],   // not installed
        ]);
        app(FeatureUsage::class)->forget();
        $counted = (new FeatureUsage(new AppData))->all();
        $this->assertSame([$first], $counted['used_what_if']['shop_ids']);
        $this->assertTrue($counted['used_purchase_plan']['available']);
        $this->assertSame([], $counted['used_purchase_plan']['shop_ids']);
    }

    public function test_feature_usage_compares_with_a_month_ago(): void
    {
        $this->seedShops();
        DailyStat::query()->create(['date' => '2026-08-30', 'installed' => 1, 'active' => 1, 'paying' => 0, 'mrr' => 0, 'plans' => [], 'features' => ['suppliers' => 0, 'onboarded' => 2]]);

        $rows = collect($this->actingAs($this->owner)->get('/features')->viewData('groups'))->flatten(1)->keyBy('key');
        $this->assertSame([1, 0], [$rows['suppliers']['count'], $rows['suppliers']['before']]);
        $this->assertNull($rows['bundles']['before']);
    }

    public function test_health_lists_failed_syncs_stale_forecasts_and_failed_emails(): void
    {
        $this->seedShops();

        $response = $this->actingAs($this->owner)->get('/health')->assertOk();
        $this->assertSame(['Tea House'], array_column($response->viewData('failed_syncs'), 'label'));
        $this->assertSame('reauthorize', $response->viewData('failed_syncs')[0]['error']);
        $this->assertSame(['Tea House'], array_column($response->viewData('stale_forecasts'), 'label'));
        $this->assertEquals(['failed' => 1, 'completed' => 1], $response->viewData('sync_runs'));
        $this->assertSame([['type' => 'ReorderDigestMail', 'sent' => 1, 'failed' => 1]], $response->viewData('emails'));
        $this->assertSame(['total' => 0, 'week' => 0], $response->viewData('failed_jobs'));
        $this->assertNull($response->viewData('web_vitals')); // this version of the app has no web_vitals table
    }

    public function test_health_shows_the_75th_percentile_of_web_vitals_against_the_built_for_shopify_limits(): void
    {
        Schema::connection('app')->create('web_vitals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->string('metric');
            $t->decimal('value', 12, 4);
            $t->string('page');
            $t->timestamp('created_at')->nullable();
        });
        $rows = [];
        foreach ([1000, 2000, 3000, 4000] as $lcp) {
            $rows[] = ['shop_id' => 1, 'metric' => 'LCP', 'value' => $lcp, 'page' => '/', 'created_at' => '2026-09-25 10:00:00'];
        }
        $rows[] = ['shop_id' => 1, 'metric' => 'LCP', 'value' => 9000, 'page' => '/', 'created_at' => '2026-08-01 10:00:00']; // older than 28 days
        $rows[] = ['shop_id' => 1, 'metric' => 'CLS', 'value' => 0.05, 'page' => '/', 'created_at' => '2026-09-25 10:00:00'];
        DB::connection('app')->table('web_vitals')->insert($rows);

        $vitals = collect($this->actingAs($this->owner)->get('/health')->assertOk()->assertSee('App speed in the Shopify admin')->viewData('web_vitals'))->keyBy('metric');
        $this->assertSame([3000.0, 4, false, false], [$vitals['LCP']['p75'], $vitals['LCP']['samples'], $vitals['LCP']['ok'], $vitals['LCP']['enough']]);
        $this->assertTrue($vitals['CLS']['ok']);
        $this->assertNull($vitals['INP']['p75']);
    }
}
