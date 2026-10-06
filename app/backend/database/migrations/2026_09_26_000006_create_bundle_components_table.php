<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_variant_id')->constrained('variants')->cascadeOnDelete();
            $table->foreignId('component_variant_id')->constrained('variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1); // units of component per bundle sold
            // shopify = native Shopify bundle (orders already contain the component lines);
            // manual = defined by the merchant in Settings (component demand is derived from bundle sales).
            $table->string('source', 10)->default('manual');
            $table->timestamps();

            $table->unique(['bundle_variant_id', 'component_variant_id']);
            $table->index('component_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_components');
    }
};
