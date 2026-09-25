<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable(); // merchant's alert address (not customer data)
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 10)->default('daily'); // daily | weekly digest, never per-SKU spam
            $table->unsignedTinyInteger('weekly_day')->default(1); // 1 = Monday (ISO)
            $table->timestamps();
        });

        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40); // App\Enums\AlertType
            $table->date('stockout_date')->nullable(); // lets us skip re-alerting the same stock-out
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['shop_id', 'sent_at']);
            $table->index(['variant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
        Schema::dropIfExists('alert_settings');
    }
};
