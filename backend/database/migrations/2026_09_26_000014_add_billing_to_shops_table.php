<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('plan_interval', 10)->nullable()->after('plan');   // monthly | annual
            $table->string('subscription_id')->nullable()->after('plan_interval'); // AppSubscription gid
            $table->string('subscription_status', 20)->nullable()->after('subscription_id');
            $table->timestamp('plan_renews_at')->nullable()->after('subscription_status');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['plan_interval', 'subscription_id', 'subscription_status', 'plan_renews_at']);
        });
    }
};
