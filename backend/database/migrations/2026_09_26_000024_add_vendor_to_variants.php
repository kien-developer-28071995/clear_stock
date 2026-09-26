<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // From the Shopify product: used to create suppliers from vendors and to filter.
        Schema::table('variants', function (Blueprint $table) {
            $table->string('vendor')->nullable()->after('title');
            $table->string('product_type')->nullable()->after('vendor');
            $table->index(['shop_id', 'vendor']);
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'vendor']);
            $table->dropColumn(['vendor', 'product_type']);
        });
    }
};
