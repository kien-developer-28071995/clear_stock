<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ABC classification: A/B/C by share of the last 90 days' revenue (net units x current price).
        // Order prices are never stored; the variant's current price is the only price the app keeps.
        Schema::table('variants', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->after('unit_cost');
            $table->char('abc_class', 1)->nullable()->after('price');                 // null = not classified (no price)
            $table->decimal('revenue_90d', 14, 2)->default(0)->after('abc_class');
            $table->decimal('revenue_share', 7, 6)->default(0)->after('revenue_90d'); // 0..1 of the shop's total
            $table->index(['shop_id', 'abc_class']);
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'abc_class']);
            $table->dropColumn(['price', 'abc_class', 'revenue_90d', 'revenue_share']);
        });
    }
};
