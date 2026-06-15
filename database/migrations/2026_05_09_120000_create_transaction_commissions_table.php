<?php

use App\Models\TransactionCommission;
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
        Schema::create('transaction_commissions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuidMorphs('accountable');
            $table->uuid('transaction_uuid')->nullable()->index();
            $table->uuid('event_uuid')->nullable()->index();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->uuid('agent_uuid')->nullable()->index();
            $table->decimal('gross_amount', 12, 4)->default(0);
            $table->decimal('markup_amount', 12, 4)->default(0);
            $table->decimal('tax_and_fees', 12, 4)->default(0);
            $table->timestamp('original_paid_at')->nullable();
            $table->index('original_paid_at');
            $table->decimal('net_amount', 12, 4)->default(0);
            $table->decimal('ticketoc_commission_percent', 5, 2)->default(0);
            $table->decimal('ticketoc_commission', 12, 4)->default(0);
            $table->decimal('ticketoc_net_commission', 12, 4)->default(0);
            $table->decimal('agent_commission_percent', 5, 2)->default(0);
            $table->decimal('agent_commission', 12, 4)->default(0);
            $table->string('payment_provider')->index();
            $table->string('payment_method')->nullable()->index();
            $table->string('payment_id')->nullable()->index();
            $table->decimal('payment_gateway_commission_percent', 6, 3)->default(0);
            $table->decimal('payment_gateway_fixed_fee', 12, 4)->default(0);
            $table->decimal('payment_gateway_commission', 12, 4)->default(0);

            $table->string('currency', 8)->default('PHP');

            $table->string('transaction_type')
                ->default(TransactionCommission::TYPE['TRANSACTION'])
                ->index();

            $table->timestamp('date_paid')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['payment_provider', 'payment_method']);
            $table->index(['organization_uuid', 'date_paid']);
            $table->index(['event_uuid', 'date_paid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_commissions');
    }
};
