<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily units per variant per fulfilling location (Growth plan, multi-location shops).
 * Source: order fulfillment orders' assigned location. Aggregates only, like daily_sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_daily_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('units_sold')->default(0);
            $table->boolean('was_in_stock')->default(true);

            $table->unique(['variant_id', 'location_id', 'date']);
            $table->index(['shop_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_daily_sales');
    }
};
