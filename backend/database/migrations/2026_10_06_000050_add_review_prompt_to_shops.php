<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When Shopify's review dialog was shown to this shop (or Shopify said it never will be):
        // the app asks once, after that never again.
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('review_prompted_at')->nullable();
            $table->string('review_prompt_result', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['review_prompted_at', 'review_prompt_result']);
        });
    }
};
