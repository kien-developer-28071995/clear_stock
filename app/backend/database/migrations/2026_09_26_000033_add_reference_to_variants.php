<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New products: borrow the sales rate of a similar product until they have their own history.
        Schema::table('variants', function (Blueprint $table) {
            $table->foreignId('reference_variant_id')->nullable()->after('max_stock')->constrained('variants')->nullOnDelete();
            $table->unsignedSmallInteger('reference_percent')->nullable()->after('reference_variant_id'); // null = 100%
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_variant_id');
            $table->dropColumn('reference_percent');
        });
    }
};
