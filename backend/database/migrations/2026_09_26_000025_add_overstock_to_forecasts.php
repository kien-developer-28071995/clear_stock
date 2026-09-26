<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Overstock: stock (+ on the way) beyond the level the forecast says to hold.
        Schema::table('forecasts', function (Blueprint $table) {
            $table->unsignedInteger('target_stock')->default(0)->after('suggested_qty'); // order-up-to level
            $table->unsignedInteger('excess_units')->default(0)->after('target_stock');   // above that level
        });
    }

    public function down(): void
    {
        Schema::table('forecasts', fn (Blueprint $table) => $table->dropColumn(['target_stock', 'excess_units']));
    }
};
