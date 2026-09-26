<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shopify's purchase order CSV import matches products by SKU or barcode.
        Schema::table('variants', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }
};
