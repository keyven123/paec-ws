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
            $table->uuid('affiliate_partner_uuid')->nullable()->index();
            $table->uuid('voucher_uuid')->nullable()->index();
            $table->decimal('sub_total', 8, 4);
            $table->string('markup_type')->nullable();
            $table->decimal('markup_value', 8, 4)->nullable();
            $table->decimal('markup_amount', 12, 4)->default(0);
            $table->decimal('markup_discount', 12, 4)->default(0);
            $table->decimal('tax_amount', 8, 4)->default(0);
            $table->decimal('discount', 8, 4)->default(0);
            $table->uuid('promo_code_uuid')->nullable();
            $table->decimal('promo_code_discount', 8, 4)->default(0);
            $table->dateTime('valid_until')->nullable();
            $table->decimal('total_amount', 8, 4);
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
