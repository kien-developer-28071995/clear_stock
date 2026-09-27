<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekdays the merchant places orders with this supplier (ISO 1 = Monday ... 7 = Sunday), e.g. [1, 4].
        // A product's reorder date moves back to the last of these days on or before it. Null = any day.
        Schema::table('suppliers', function (Blueprint $table) {
            $table->json('order_weekdays')->nullable()->after('order_cycle_days');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('order_weekdays'));
    }
};
