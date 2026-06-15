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
        Schema::create('temp_transactions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->index();
            $table->uuid('event_uuid')->index();
            $table->uuid('schedule_uuid')->nullable()->index();
            $table->uuid('schedule_time_uuid')->nullable()->index();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->uuid('voucher_uuid')->nullable()->index();
            $table->decimal('sub_total', 8, 2);
            $table->decimal('tax_amount', 8, 2)->default(0);
            $table->decimal('discount', 8, 2)->default(0);
            $table->decimal('total_amount', 8, 2);
            $table->timestamps();
            $table->timestamp('marketing_followup_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temp_transactions');
    }
};
