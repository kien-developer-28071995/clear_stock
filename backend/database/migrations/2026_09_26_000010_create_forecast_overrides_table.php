<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->string('field', 30); // App\Enums\OverrideField
            $table->decimal('value', 12, 3);
            $table->string('note', 500)->nullable();
            $table->timestamp('expires_at')->nullable(); // e.g. a promo period; null = until removed
            $table->timestamps();

            $table->unique(['variant_id', 'field']);
            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_overrides');
    }
};
