<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latest forecast per variant. location_id null = all locations combined (MVP);
 * per-location rows are for the Growth plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->integer('current_stock');
            $table->decimal('avg_daily_sales', 10, 3);
            $table->decimal('days_of_cover', 8, 1)->nullable(); // null = no sales, infinite cover
            $table->date('stockout_date')->nullable();
            $table->date('reorder_date')->nullable();
            $table->unsignedInteger('reorder_point');
            $table->unsignedInteger('suggested_qty');
            $table->string('confidence', 10); // low | medium | high
            $table->json('explanation');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['variant_id', 'location_id']);
            $table->index(['shop_id', 'stockout_date']);
            $table->index(['shop_id', 'days_of_cover']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecasts');
    }
};
