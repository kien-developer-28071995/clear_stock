<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A small copy of the Clear Stock app's tables (only the columns the reports read) in an in-memory database. */
trait AppSchema
{
    protected function createAppSchema(): void
    {
        DB::purge('app');
        $schema = Schema::connection('app');

        $schema->create('shops', function (Blueprint $t) {
            $t->id();
            $t->string('domain');
            $t->string('name')->nullable();
            $t->string('plan')->default('free');
            $t->string('plan_interval')->nullable();
            $t->string('subscription_status')->nullable();
            $t->timestamp('plan_renews_at')->nullable();
            $t->timestamp('trial_started_at')->nullable();
            $t->string('currency')->nullable();
            $t->string('timezone')->default('UTC');
            $t->string('locale')->nullable();
            $t->string('scopes')->nullable();
            $t->boolean('filter_sales_spikes')->default(true);
            $t->decimal('order_budget', 12, 2)->nullable();
            $t->timestamp('onboarded_at')->nullable();
            $t->string('sync_status')->default('completed');
            $t->text('sync_error')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamp('forecasted_at')->nullable();
            $t->timestamp('installed_at')->nullable();
            $t->timestamp('uninstalled_at')->nullable();
            $t->timestamps();
        });
        $schema->create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->string('name');
            $t->integer('order_cycle_days')->nullable();
            $t->text('order_weekdays')->nullable();
            $t->boolean('auto_email')->default(false);
        });
        $schema->create('variants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('tracked')->default(true);
            $t->integer('min_stock')->nullable();
            $t->integer('max_stock')->nullable();
            $t->integer('min_order_qty')->nullable();
            $t->integer('pack_size')->nullable();
            $t->integer('lead_time_override')->nullable();
            $t->integer('safety_days')->nullable();
            $t->decimal('cost_override', 12, 4)->nullable();
            $t->boolean('discontinued')->default(false);
            $t->boolean('alerts_muted')->default(false);
            $t->unsignedBigInteger('reference_variant_id')->nullable();
        });
        $schema->create('manual_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->integer('quantity')->default(1);
            $t->string('status')->default('open');
        });
        $schema->create('alert_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->string('email')->nullable();
            $t->boolean('enabled')->default(true);
            $t->string('realtime')->default('off');
        });
        foreach (['bundle_components', 'forecast_overrides', 'sales_events', 'supplier_emails', 'inventory_transfers', 'flow_subscriptions'] as $table) {
            $schema->create($table, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shop_id');
            });
        }
        $schema->create('forecasts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('location_id')->nullable();
        });
        $schema->create('locations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->boolean('is_active')->default(true);
        });
        $schema->create('sync_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->string('status');
            $t->timestamps();
        });
        $schema->create('email_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('mailable');
            $t->string('status');
            $t->timestamps();
        });
        $schema->create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('failed_at');
        });
    }

    /** @return int the new shop's id in the app */
    protected function appShop(array $attributes = []): int
    {
        return (int) DB::connection('app')->table('shops')->insertGetId($attributes + [
            'domain' => 'shop-'.uniqid().'.myshopify.com', 'plan' => 'free', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function updateAppShop(int $id, array $attributes): void
    {
        DB::connection('app')->table('shops')->where('id', $id)->update($attributes);
    }
}
