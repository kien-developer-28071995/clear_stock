<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orders placed outside Shopify (email, phone, a supplier portal): Shopify shows no "incoming"
        // for them, so the app counts them as on the way until received, cancelled or past their date.
        Schema::create('manual_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->date('ordered_on');
            $table->date('expected_on');
            $table->string('reference', 100)->nullable();
            $table->string('source', 16)->default('manual');   // manual, supplier_email
            $table->string('status', 10)->default('open');     // open, received, cancelled
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['variant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_orders');
    }
};
