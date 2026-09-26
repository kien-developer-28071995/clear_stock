<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every email the app sends (or fails to send): metadata only, never the body.
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete(); // deleted with the shop (shop/redact)
            $table->string('mailable');                  // e.g. App\Mail\ReorderDigestMail
            $table->string('status', 16);                // sent | failed
            $table->string('subject')->nullable();
            $table->string('from')->nullable();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->json('reply_to')->nullable();
            $table->json('attachments')->nullable();     // file names
            $table->string('message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
