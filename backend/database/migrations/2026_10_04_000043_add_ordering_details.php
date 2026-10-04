<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The supplier's own code for a product, printed on purchase orders.
        Schema::table('variants', function (Blueprint $table) {
            $table->string('supplier_sku')->nullable()->after('barcode');
        });

        // Partial deliveries: units already received of an order placed outside Shopify.
        // The rest stays "on the way" until the order is fully received or closed.
        Schema::table('manual_orders', function (Blueprint $table) {
            $table->unsignedInteger('received_quantity')->default(0)->after('quantity');
        });

        // A location whose stock is not for sale (returns, damaged goods, showroom): left out of
        // the stock the forecast counts. Sales fulfilled from it still count as demand.
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('excluded')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn('excluded'));
        Schema::table('manual_orders', fn (Blueprint $table) => $table->dropColumn('received_quantity'));
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn('supplier_sku'));
    }
};
