<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Freight, duty and handling on top of the supplier's price, as a share of it: money
            // figures (stock value, cash tied up, plan spend) use the landed cost.
            $table->decimal('landed_cost_percent', 5, 2)->nullable()->after('order_weekdays');
            // The supplier's minimum order value (shop currency): orders below it are flagged.
            $table->decimal('min_order_value', 12, 2)->nullable()->after('landed_cost_percent');
        });
        // unit_cost currently includes the supplier's landed cost share (reset when it no longer applies).
        Schema::table('variants', function (Blueprint $table) {
            $table->boolean('landed_cost_applied')->default(false)->after('cost_override');
        });
    }

    public function down(): void
    {
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn('landed_cost_applied'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['landed_cost_percent', 'min_order_value']));
    }
};
