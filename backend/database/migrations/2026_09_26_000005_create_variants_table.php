<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_variant_id');
            $table->unsignedBigInteger('shopify_product_id');
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->string('product_title');
            $table->string('title')->nullable(); // variant title, null for "Default Title"
            $table->string('sku')->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable(); // from InventoryItem.unitCost, shop currency
            $table->boolean('tracked')->default(true);        // inventory tracked by Shopify
            $table->boolean('is_active')->default(true);      // false when product archived/deleted
            $table->timestamp('shopify_created_at')->nullable(); // days before this are not "no sales" days

            // Merchant settings (Settings screen)
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_override')->nullable(); // days
            $table->unsignedSmallInteger('safety_days')->nullable();        // null = shop default
            $table->boolean('is_bundle')->default(false);
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_variant_id']);
            $table->index(['shop_id', 'sku']);
            $table->index(['shop_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
