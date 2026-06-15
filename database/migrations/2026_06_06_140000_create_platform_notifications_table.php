<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notifications', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('notifiable_type');
            $table->uuid('notifiable_uuid');
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url', 1024)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_uuid', 'read_at'], 'pn_notifiable_read_index');
            $table->index('created_at', 'pn_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notifications');
    }
};
