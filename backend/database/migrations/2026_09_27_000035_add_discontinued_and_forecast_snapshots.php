<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merchant stops reordering a product (discontinued / sell-through): no order suggestions or
        // alerts, and it does not count toward the Free plan's product limit.
        Schema::table('variants', function (Blueprint $table) {
            $table->boolean('discontinued')->default(false)->after('alerts_muted');
        });

        // The forecast sales rate once a week, compared with what really sold over the following
        // weeks (forecast accuracy). Small: one row per forecasted product per week.
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->decimal('avg_daily_sales', 10, 2);
            $table->string('avg_source', 12);      // computed, override, reference
            $table->boolean('has_bundles')->default(false); // demand included sales inside bundles
            $table->timestamp('created_at')->nullable();

            $table->unique(['variant_id', 'week_start']);
            $table->index(['shop_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn('discontinued'));
    }
};
