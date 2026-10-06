<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real-time stock alerts (Growth): off | out_of_stock | all. Off by default, never pushed on merchants.
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->string('realtime', 20)->default('off')->after('weekly_day');
        });

        // Merchant silenced this product: no digest or real-time email mentions it.
        Schema::table('variants', function (Blueprint $table) {
            $table->boolean('alerts_muted')->default(false)->after('max_stock');
        });

        // updated_at of the last inventory_levels/update applied: Shopify doesn't guarantee webhook order.
        Schema::table('inventory_levels', function (Blueprint $table) {
            $table->timestamp('shopify_updated_at')->nullable()->after('incoming');
        });

        // Shop-specific inventory_levels/update subscription, only while real-time alerts are on.
        Schema::table('shops', function (Blueprint $table) {
            $table->string('realtime_webhook_id')->nullable()->after('forecasted_at');
        });

        // Last known stock level of each variant (ok / low / out). Alerts fire on a worsening
        // transition only; pending_at marks transitions waiting for the next batched email.
        Schema::create('variant_alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('level', 10)->default('ok');
            $table->timestamp('pending_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'pending_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_alert_states');
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('realtime_webhook_id'));
        Schema::table('inventory_levels', fn (Blueprint $table) => $table->dropColumn('shopify_updated_at'));
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn('alerts_muted'));
        Schema::table('alert_settings', fn (Blueprint $table) => $table->dropColumn('realtime'));
    }
};
