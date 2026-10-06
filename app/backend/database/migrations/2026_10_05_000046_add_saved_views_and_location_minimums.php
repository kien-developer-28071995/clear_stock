<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A named set of product list filters the merchant comes back to ("A class, selling faster").
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('filters');
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        // Manual reorder point of a product at one location (Growth): replaces the computed one there.
        Schema::create('location_minimums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_stock');
            $table->timestamps();

            $table->unique(['variant_id', 'location_id']);
            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_minimums');
        Schema::dropIfExists('saved_views');
    }
};
