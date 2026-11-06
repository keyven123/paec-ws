<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_pixel_event_logs', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('event_name', 100)->index();
            $table->string('source', 32)->default('capi')->index(); // browser | capi | admin_test
            $table->string('status', 32)->default('sent')->index(); // sent | failed | skipped
            $table->string('pixel_id', 64)->nullable();
            $table->string('event_id', 191)->nullable()->index();
            $table->string('content_name')->nullable();
            $table->string('page_url', 1000)->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->uuid('transaction_uuid')->nullable()->index();
            $table->uuid('event_uuid')->nullable()->index();
            $table->string('order_id', 191)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_pixel_event_logs');
    }
};
