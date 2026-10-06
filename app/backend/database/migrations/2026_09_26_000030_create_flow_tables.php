<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shopify Flow lifecycle callbacks: whether the shop has an active workflow using one of
        // our triggers. Triggers are only sent to shops that use them (Shopify's recommendation).
        Schema::create('flow_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('definition_id');          // flow_trigger_definition_id from the callback
            $table->boolean('enabled');
            $table->timestamp('event_at', 3);         // callback timestamp: older callbacks never win
            $table->timestamps();
            $table->unique(['shop_id', 'definition_id']);
        });

        // What each trigger last saw, so a trigger fires once per change (not every night).
        Schema::create('flow_trigger_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 16);            // variant | supplier
            $table->unsignedBigInteger('subject_id');
            $table->json('state');
            $table->timestamps();
            $table->unique(['shop_id', 'subject', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_trigger_states');
        Schema::dropIfExists('flow_subscriptions');
    }
};
