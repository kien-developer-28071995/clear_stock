<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Draft transfers created in Shopify from our suggestions (Growth): history, and their
        // quantities count as already moved so the same stock isn't suggested twice.
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_transfer_id');
            $table->string('name');                      // Shopify's transfer name, e.g. #T0001
            $table->foreignId('origin_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('destination_location_id')->constrained('locations')->cascadeOnDelete();
            $table->json('items');                       // [{variant_id, quantity}]
            $table->unsignedInteger('total_units');
            $table->timestamp('created_at');

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
