<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Forecast profile: which averaging windows and weights a product uses (config/forecast.php
        // `profiles`). The shop's is the default; a product's own wins.
        Schema::table('shops', function (Blueprint $table) {
            $table->string('forecast_profile', 16)->default('balanced')->after('filter_sales_spikes');
        });
        Schema::table('variants', function (Blueprint $table) {
            $table->string('forecast_profile', 16)->nullable()->after('discontinued');
        });

        // Recent sales vs the weeks before, in percent (null = not enough sales to tell).
        Schema::table('forecasts', function (Blueprint $table) {
            $table->smallInteger('trend_percent')->nullable()->after('lost_units_30d');
        });
    }

    public function down(): void
    {
        Schema::table('forecasts', fn (Blueprint $table) => $table->dropColumn('trend_percent'));
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn('forecast_profile'));
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('forecast_profile'));
    }
};
