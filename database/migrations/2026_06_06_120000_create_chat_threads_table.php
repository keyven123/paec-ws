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
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('venue_inquiry_uuid');
            $table->uuid('venue_listing_uuid');
            $table->uuid('organization_uuid')->nullable();
            $table->uuid('customer_uuid')->nullable();
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('customer_last_read_at')->nullable();
            $table->timestamp('merchant_last_read_at')->nullable();
            $table->timestamps();

            $table->unique('venue_inquiry_uuid', 'chat_threads_inquiry_unique');
            $table->index('organization_uuid', 'chat_threads_organization_index');
            $table->index('customer_uuid', 'chat_threads_customer_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_threads');
    }
};
