<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual min/max (as in Stocky): reorder at min, order up to max. Null = forecast decides.
        Schema::table('variants', function (Blueprint $table) {
            $table->unsignedInteger('min_stock')->nullable()->after('pack_size');
            $table->unsignedInteger('max_stock')->nullable()->after('min_stock');
        });
    }

    public function down(): void
    {
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn(['min_stock', 'max_stock']));
    }
};
