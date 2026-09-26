<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defaults for the supplier's products; a product's own minimum order / pack size wins.
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('min_order_qty')->nullable()->after('lead_time_days');
            $table->unsignedInteger('pack_size')->nullable()->after('min_order_qty');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['min_order_qty', 'pack_size']));
    }
};
