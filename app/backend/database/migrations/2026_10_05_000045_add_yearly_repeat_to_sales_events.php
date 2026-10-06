<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A season: the same change on the same dates every year (summer, the holidays).
        Schema::table('sales_events', function (Blueprint $table) {
            $table->boolean('repeats_yearly')->default(false)->after('multiplier');
        });
    }

    public function down(): void
    {
        Schema::table('sales_events', fn (Blueprint $table) => $table->dropColumn('repeats_yearly'));
    }
};
