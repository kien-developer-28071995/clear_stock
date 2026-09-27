<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monthly stock purchasing budget (shop currency): what to reorder first when cash is short.
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('order_budget', 12, 2)->nullable()->after('filter_sales_spikes');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('order_budget'));
    }
};
