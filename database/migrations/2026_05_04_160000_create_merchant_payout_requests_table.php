<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payout_requests', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('organization_uuid')->index('merchant_payout_req_org_uuid');
            $table->uuid('organization_bank_uuid')->index('merchant_payout_req_org_bank_uuid');
            $table->uuid('event_uuid')->index('merchant_payout_req_event_uuid')->nullable();
            $table->decimal('amount_requested', 15, 2);
            $table->string('currency', 8)->default('PHP');
            $table->string('status', 32)->default('pending')->index('merchant_payout_req_status');
            $table->string('reference_number')->nullable()->index('merchant_payout_req_reference_number');
            $table->text('merchant_note')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('void_at')->nullable()->index('merchant_payout_req_void_at');
            $table->uuid('void_by_uuid')->nullable()->index('merchant_payout_req_void_by_uuid');
            $table->timestamp('processed_at')->nullable()->index('merchant_payout_req_processed_at');
            $table->uuid('processed_by_uuid')->nullable()->index('merchant_payout_req_processed_by_uuid');
            $table->uuid('requested_by_admin_uuid')->nullable()->index('merchant_payout_req_requested_by_admin_uuid');
            $table->timestamps();
            $table->index(['created_at', 'updated_at'], 'merchant_payout_req_created_at_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payout_requests');
    }
};
