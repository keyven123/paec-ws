<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reminder_logs', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('transaction_uuid')->index();
            $table->uuid('user_uuid')->nullable()->index();
            $table->uuid('event_uuid')->nullable()->index();
            $table->uuid('schedule_uuid')->nullable()->index();
            $table->uuid('schedule_time_uuid')->nullable()->index();
            $table->string('group_key', 160)->nullable()->index();
            $table->string('reminder_type', 16)->index();
            $table->timestamp('sent_at')->nullable();
            
            $table->timestamps();

            $table->unique(['transaction_uuid', 'reminder_type'], 'event_reminder_logs_unique_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminder_logs');
    }
};
