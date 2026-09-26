<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One email per streak of failed syncs; cleared by the next successful sync.
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('sync_failure_notified_at')->nullable()->after('sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('sync_failure_notified_at'));
    }
};
