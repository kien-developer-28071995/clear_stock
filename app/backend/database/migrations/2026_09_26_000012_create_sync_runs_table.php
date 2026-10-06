<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per sync attempt: drives the progress bar and gives an audit trail for failures. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);    // App\Enums\SyncType
            $table->string('status', 12);  // App\Enums\SyncRunStatus
            $table->string('stage', 20);   // App\Enums\SyncStage
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->date('window_start');  // first order day (shop timezone) included in this run
            $table->timestamp('variants_updated_since')->nullable(); // null = full catalog
            $table->json('operations')->nullable(); // bulk operations by key: id, status, object_count, url
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'id']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
