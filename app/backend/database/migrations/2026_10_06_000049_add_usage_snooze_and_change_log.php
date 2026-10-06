<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How often a shop used a feature that stores nothing (what-if, purchase plan, exports):
        // one row per shop, feature and day (UTC), counted up. Kept 400 days.
        Schema::create('feature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->date('day');
            $table->unsignedInteger('count')->default(1);

            $table->unique(['shop_id', 'feature', 'day']);
            $table->index(['feature', 'day']);
        });

        // "Not now": the product stays out of the reorder list, the home list and alert emails
        // until this day (shop's calendar). Its forecast and status do not change.
        Schema::table('variants', function (Blueprint $table) {
            $table->date('snoozed_until')->nullable();
        });

        // What the merchant changed on a product and when: settings and forecast adjustments,
        // old value next to new. Values only (numbers, names of the shop's own suppliers). Kept 180 days.
        Schema::create('change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);
            $table->string('old_value', 191)->nullable();
            $table->string('new_value', 191)->nullable();
            $table->string('source', 20);     // app, bulk, import, extension
            $table->timestamp('created_at')->nullable();

            $table->index(['variant_id', 'id']);
            $table->index(['shop_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_logs');
        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn('snoozed_until');
        });
        Schema::dropIfExists('feature_events');
    }
};
