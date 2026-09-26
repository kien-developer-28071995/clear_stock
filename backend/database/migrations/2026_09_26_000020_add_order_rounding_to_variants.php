<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suggested order quantities are rounded up to what the supplier accepts.
        Schema::table('variants', function (Blueprint $table) {
            $table->unsignedInteger('min_order_qty')->nullable()->after('safety_days'); // MOQ in units
            $table->unsignedInteger('pack_size')->nullable()->after('min_order_qty');   // units per case
        });
    }

    public function down(): void
    {
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn(['min_order_qty', 'pack_size']));
    }
};
