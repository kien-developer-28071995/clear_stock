<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promotions and other known sales changes the merchant enters (Black Friday x2, a closure x0.5).
        // Upcoming ones raise or lower the demand used for orders; past ones are counted at their normal level.
        Schema::create('sales_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('multiplier', 5, 2);
            $table->string('applies_to', 10)->default('all'); // all, supplier, products
            $table->foreignId('supplier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('variant_ids')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_events');
    }
};
