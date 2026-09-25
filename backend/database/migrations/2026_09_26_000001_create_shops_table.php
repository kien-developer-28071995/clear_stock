<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique(); // xyz.myshopify.com
            $table->string('name')->nullable();

            // Expiring offline token + refresh token (encrypted at rest via model casts).
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->string('scopes', 1000)->nullable();

            $table->string('plan', 20)->default('free');
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 64)->default('UTC'); // IANA, e.g. America/New_York

            // Shop-wide defaults asked during onboarding; supplier/SKU values override them.
            $table->unsignedSmallInteger('default_lead_time_days')->default(14);
            $table->unsignedSmallInteger('default_safety_days')->default(7);
            $table->timestamp('onboarded_at')->nullable();

            $table->string('sync_status', 20)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
