<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregated daily sales per variant (in the shop's timezone). Raw orders and
 * customer data are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('units_sold')->default(0);
            $table->unsignedInteger('units_returned')->default(0);
            $table->integer('end_of_day_stock')->nullable(); // null when unknown
            $table->boolean('was_in_stock')->default(true);  // false days are excluded from averages

            $table->unique(['variant_id', 'date']);
            $table->index(['shop_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sales');
    }
};
