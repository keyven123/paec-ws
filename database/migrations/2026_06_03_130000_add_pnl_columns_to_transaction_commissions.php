<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends transaction_commissions with columns required by the Platform P&L
 * service when it aggregates via SQL instead of PHP cursor loops.
 *
 *  markup_amount     — markup portion of the transaction amount. Needed so the
 *                      P&L can compute platform_fee on net_selling only
 *                      (net_selling = gross_amount − markup_amount − tax_and_fees).
 *
 *  tax_and_fees      — VAT / convenience-fee portion. Same reason as above.
 *
 *  original_paid_at  — Only populated on rows with transaction_type = 'refund'.
 *                      Stores the original transaction's paid_at so the P&L can
 *                      detect cross-month refunds in a single SQL aggregate:
 *                      DATE_FORMAT(date_paid,'%Y-%m') != DATE_FORMAT(original_paid_at,'%Y-%m')
 *
 * After running this migration, execute:
 *   php artisan app:backfill-transaction-commissions --update-columns
 *   php artisan app:backfill-transaction-commissions --backfill-refunds
 * to populate these columns for historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_commissions', function (Blueprint $table) {
            $table->decimal('markup_amount', 12, 2)->default(0)->after('gross_amount');
            $table->decimal('tax_and_fees', 12, 2)->default(0)->after('markup_amount');
            $table->timestamp('original_paid_at')->nullable()->after('date_paid');
            $table->index('original_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_commissions', function (Blueprint $table) {
            $table->dropIndex(['original_paid_at']);
            $table->dropColumn(['markup_amount', 'tax_and_fees', 'original_paid_at']);
        });
    }
};
