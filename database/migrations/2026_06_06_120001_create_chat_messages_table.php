<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('chat_thread_uuid');
            $table->string('sender_type'); // customer | merchant
            $table->uuid('sender_uuid')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('message_type')->default('text');
            $table->text('body');
            $table->uuid('attachment_upload_uuid')->nullable();
            $table->string('attachment_name')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['chat_thread_uuid', 'created_at'], 'chat_messages_thread_created_index');
            $table->index('attachment_upload_uuid', 'chat_messages_attachment_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
