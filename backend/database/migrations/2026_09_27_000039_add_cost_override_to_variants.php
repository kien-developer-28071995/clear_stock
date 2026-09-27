<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // unit_cost stays the cost every report uses; it is now cost_override (entered in the app)
        // or else Shopify's cost, which the sync keeps in shopify_unit_cost.
        Schema::table('variants', function (Blueprint $table) {
            $table->decimal('shopify_unit_cost', 12, 4)->nullable()->after('unit_cost');
            $table->decimal('cost_override', 12, 4)->nullable()->after('shopify_unit_cost');
        });
        DB::table('variants')->whereNotNull('unit_cost')->update(['shopify_unit_cost' => DB::raw('unit_cost')]);
    }

    public function down(): void
    {
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn(['shopify_unit_cost', 'cost_override']));
    }
};
