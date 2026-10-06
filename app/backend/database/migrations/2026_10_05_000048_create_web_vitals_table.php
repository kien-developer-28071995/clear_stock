<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Web vitals the Shopify admin measured for the embedded app (App Bridge web vitals API):
        // the numbers Built for Shopify judges (LCP, CLS, INP at the 75th percentile over 28 days).
        // One row per measurement, kept 35 days.
        Schema::create('web_vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 8);          // LCP, CLS, INP, FCP, TTFB
            $table->decimal('value', 12, 4);      // ms, or unitless for CLS
            $table->string('page', 60);           // route pattern, e.g. /products/:id
            $table->timestamp('created_at')->nullable();

            $table->index(['metric', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vitals');
    }
};
