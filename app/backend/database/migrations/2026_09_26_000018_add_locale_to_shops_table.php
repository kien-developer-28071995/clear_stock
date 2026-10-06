<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // App language chosen in Settings; null = follow the Shopify admin language.
            $table->string('locale', 10)->nullable()->after('timezone');
        });

        // sync_error now holds {code, params} JSON instead of an English sentence.
        DB::table('shops')->whereNotNull('sync_error')->update(['sync_error' => null]);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
