<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            // Weekly summary email (opt-in, every plan): one email a week on `weekly_day`.
            $table->boolean('weekly_summary')->default(false)->after('realtime');
            $table->timestamp('weekly_summary_sent_at')->nullable()->after('weekly_summary');
            // Reorder digest also posted to a Slack incoming webhook (encrypted: the URL is a secret).
            $table->text('slack_webhook_url')->nullable()->after('weekly_summary_sent_at');
            // Also alert when a product has this many days of stock left or fewer (null = reorder date only).
            $table->unsignedSmallInteger('cover_days')->nullable()->after('slack_webhook_url');
        });

        // Stock on hand once a day (units and value at cost): the inventory value history.
        // One small row per shop per day; Shopify itself keeps no such history.
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('units');
            $table->decimal('value', 14, 2);
            $table->unsignedInteger('products_in_stock');
            $table->unsignedInteger('products_missing_cost'); // in stock without a unit cost: not in `value`
            $table->timestamps();

            $table->unique(['shop_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
        Schema::table('alert_settings', fn (Blueprint $table) => $table->dropColumn(['weekly_summary', 'weekly_summary_sent_at', 'slack_webhook_url', 'cover_days']));
    }
};
