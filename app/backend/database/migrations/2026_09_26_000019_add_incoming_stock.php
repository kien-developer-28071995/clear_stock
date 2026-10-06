<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shopify "incoming" quantity: stock on the way (purchase orders, transfers).
        Schema::table('inventory_levels', function (Blueprint $table) {
            $table->integer('incoming')->default(0)->after('available');
        });
        Schema::table('forecasts', function (Blueprint $table) {
            $table->integer('incoming_stock')->default(0)->after('current_stock');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_levels', fn (Blueprint $table) => $table->dropColumn('incoming'));
        Schema::table('forecasts', fn (Blueprint $table) => $table->dropColumn('incoming_stock'));
    }
};
