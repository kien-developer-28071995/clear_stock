<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Email the supplier a purchase order automatically when their products are due (Growth).
            $table->boolean('auto_email')->default(false)->after('lead_time_days');
        });

        // Purchase orders emailed to suppliers: history + at most one automatic email per interval.
        Schema::create('supplier_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 16);              // manual | auto
            $table->string('to_email');
            $table->string('reply_to')->nullable();
            $table->json('items');                      // [{variant_id, sku, name, quantity}]
            $table->unsignedInteger('total_units');
            $table->timestamp('created_at');

            $table->index(['supplier_id', 'trigger', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_emails');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('auto_email'));
    }
};
