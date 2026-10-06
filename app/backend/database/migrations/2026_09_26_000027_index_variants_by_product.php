<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin extensions look products up by their Shopify id (product page block, bulk action).
        Schema::table('variants', function (Blueprint $table) {
            $table->index(['shop_id', 'shopify_product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'shopify_product_id']);
        });
    }
};
