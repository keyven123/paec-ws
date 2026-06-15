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

            // Polymorphic owner: usually a Transaction, but kept polymorphic so
            // future sources (chargebacks, refund requests, manual adjustments,
            // etc.) can also produce commission ledger rows.
            $table->uuidMorphs('accountable');

            // Denormalized FKs for fast aggregation (gross sales per event /
            // per organizer / per affiliate without joins).
            $table->uuid('transaction_uuid')->nullable()->index();
            $table->uuid('event_uuid')->nullable()->index();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->uuid('agent_uuid')->nullable()->index();

            // Money figures (using 12,2 — wider than the 8,2 used on the
            // transactions table — because this is the accounting source of
            // truth and may aggregate later).
            //
            // net_amount             = gross_amount − ticketoc_commission
            //                          (i.e. organizer's net payable for this txn)
            // ticketoc_net_commission = ticketoc_commission − agent_commission
            //                          − payment_gateway_fixed_fee
            //                          − payment_gateway_commission
            //                          (i.e. what TicketOC actually keeps after
            //                           paying the affiliate and the gateway)
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);

            // Platform (TicketOC) commission — snapshot percent + computed amount
            // + final amount kept after paying out affiliate and gateway fees.
            $table->decimal('ticketoc_commission_percent', 5, 2)->default(0);
            $table->decimal('ticketoc_commission', 12, 2)->default(0);
            $table->decimal('ticketoc_net_commission', 12, 2)->default(0);

            // Affiliate / agent commission — snapshot percent + computed amount.
            $table->decimal('agent_commission_percent', 5, 2)->default(0);
            $table->decimal('agent_commission', 12, 2)->default(0);

            // Payment gateway fee snapshot. The two amount columns are
            // disjoint so they can be summed safely:
            //   total_gateway_fee = payment_gateway_commission
            //                     + payment_gateway_fixed_fee
            //
            // payment_gateway_commission_percent — snapshot rate (e.g. 3.900)
            // payment_gateway_commission         — percent-only amount (e.g. PayPal 2000 × 3.9% = 78.00)
            // payment_gateway_fixed_fee          — fixed-only amount  (e.g. PayPal additional_fee = 15.00)
            $table->string('payment_provider')->index();
            $table->string('payment_method')->nullable()->index();
            $table->string('payment_id')->nullable()->index();
            $table->decimal('payment_gateway_commission_percent', 6, 3)->default(0);
            $table->decimal('payment_gateway_fixed_fee', 12, 2)->default(0);
            $table->decimal('payment_gateway_commission', 12, 2)->default(0);

            $table->string('currency', 8)->default('PHP');

            $table->string('transaction_type')
                ->default(TransactionCommission::TYPE['TRANSACTION'])
                ->index();

            $table->timestamp('date_paid')->nullable()->index();

            // Audit: snapshot of the rate map / inputs used in the computation.
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
