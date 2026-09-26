<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Setup guide state: events the app can't derive from data, skipped steps,
            // dismissed guide/tips. Most steps are derived (sync done, suppliers exist...).
            $table->json('setup_guide')->nullable()->after('onboarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('setup_guide');
        });
    }
};
