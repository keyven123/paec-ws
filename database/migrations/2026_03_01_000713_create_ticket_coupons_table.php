<?php

use App\Constants\GeneralConstants;
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
        Schema::create('ticket_coupons', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->index();
            $table->uuid('ticket_uuid')->index();
            $table->uuid('event_uuid')->index();
            $table->uuid('event_ticket_coupon_uuid')->index();
            $table->string('name')->index();
            $table->string('qr_code')->index();
            $table->string('status')->default(GeneralConstants::TICKET_COUPON_STATUSES['PENDING'])->index();
            $table->datetime('claimed_at')->nullable()->index();
            $table->uuid('scanned_by')->nullable()->index();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_coupons');
    }
};
