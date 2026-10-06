<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-off sales spikes (a wholesale order, a viral day) are capped to the usual level before averaging.
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('filter_sales_spikes')->default(true)->after('default_safety_days');
        });
        // How often the merchant orders from this supplier: the suggested order covers this many days of sales.
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('order_cycle_days')->nullable()->after('pack_size');
        });
        // Units the product would have sold on its out-of-stock days of the last 30 days.
        Schema::table('forecasts', function (Blueprint $table) {
            $table->decimal('lost_units_30d', 10, 2)->default(0)->after('excess_units');
        });
    }

    public function down(): void
    {
        Schema::table('forecasts', fn (Blueprint $table) => $table->dropColumn('lost_units_30d'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('order_cycle_days'));
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('filter_sales_spikes'));
    }
};
