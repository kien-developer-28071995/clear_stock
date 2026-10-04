<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per shop ever seen in the app's database. Kept after the app deletes the shop
        // (shop/redact, 48h after uninstall): then only the dates and plan remain, no name or domain.
        Schema::create('shop_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_shop_id')->unique();
            $table->string('domain')->nullable();
            $table->string('name')->nullable();
            $table->string('plan', 20)->default('free');
            $table->string('plan_interval', 20)->nullable();
            $table->string('currency', 8)->nullable();
            $table->timestamp('first_installed_at')->nullable();
            $table->timestamp('installed_at')->nullable();      // latest install
            $table->timestamp('uninstalled_at')->nullable();    // null = installed now
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->string('plan_at_uninstall', 20)->nullable();
            $table->timestamp('redacted_at')->nullable();       // shop data deleted in the app
            $table->timestamps();

            $table->index('uninstalled_at');
            $table->index('installed_at');
        });

        // What happened to a shop and when: installed, reinstalled, uninstalled, plan_changed, redacted.
        Schema::create('shop_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_record_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('plan_from', 20)->nullable();
            $table->string('plan_to', 20)->nullable();
            $table->string('interval', 20)->nullable();
            $table->decimal('mrr_change', 8, 2)->default(0);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
        });

        // One snapshot a day: totals for the trend charts (the app itself keeps no history).
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('installed');
            $table->unsignedInteger('active');
            $table->unsignedInteger('paying');
            $table->decimal('mrr', 10, 2);
            $table->json('plans');      // plan => installed shops
            $table->json('features');   // feature key => shops using it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
        Schema::dropIfExists('shop_events');
        Schema::dropIfExists('shop_records');
    }
};
