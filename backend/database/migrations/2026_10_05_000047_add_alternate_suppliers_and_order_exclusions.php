<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Other suppliers a product can be bought from (the main one stays variants.supplier_id),
        // each with its own cost, lead time and product code.
        Schema::create('variant_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('supplier_sku')->nullable();
            $table->timestamps();

            $table->unique(['variant_id', 'supplier_id']);
            $table->index('shop_id');
        });

        // Orders left out of the sales the forecast learns from: by order tag (wholesale...)
        // and by source (POS, draft orders). Applied when orders are aggregated, never stored per order.
        Schema::table('shops', function (Blueprint $table) {
            $table->json('excluded_order_tags')->nullable()->after('forecast_profile');
            $table->json('excluded_order_sources')->nullable()->after('excluded_order_tags');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn(['excluded_order_tags', 'excluded_order_sources']));
        Schema::dropIfExists('variant_suppliers');
    }
};
