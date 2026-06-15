<?php

use App\Constants\GeneralConstants;
use App\Models\Transaction;
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->index();
            $table->uuid('event_uuid')->index();
            $table->uuid('schedule_uuid')->nullable()->index();
            $table->uuid('schedule_time_uuid')->nullable()->index();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->string('payment_order_id')->nullable()->index();
            $table->string('payment_id')->nullable()->index();
            $table->json('payment_data')->nullable();
            $table->string('payment_provider')->nullable();
            $table->string('order_number')->unique()->index();
            $table->decimal('sub_total', 8, 2);
            $table->decimal('tax_amount', 8, 2)->default(0);
            $table->decimal('discount', 8, 2)->default(0);
            $table->uuid('promo_code_uuid')->nullable();
            $table->decimal('promo_code_discount', 8, 2)->default(0);
            $table->decimal('total_amount', 8, 2);
            $table->string('status')->index()->default(GeneralConstants::GENERAL_STATUSES['ACTIVE']);
            $table->string('payment_status')->index()->default(Transaction::PAYMENT_STATUS['PENDING']);
            $table->string('order_status')->index()->default(Transaction::ORDER_STATUS['PENDING']);
            $table->timestamp('paid_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_by']);
            $table->index(['updated_by']);
            $table->index(['created_at']);
            $table->index(['updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
