<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that share the polymorphic transaction header.
     */
    private array $tables = ['transactions', 'temp_transactions'];

    /**
     * Run the migrations.
     *
     * Adds a polymorphic "subject" to the transaction header so a single
     * transactions / temp_transactions table can serve modules other than
     * events (e.g. venue bookings). The legacy event_uuid column is kept as a
     * nullable, denormalized FK for analytics, search and commission
     * aggregation — mirroring the approach used on transaction_commissions.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->string('transactionable_type')->nullable()->after('uuid');
                $table->uuid('transactionable_uuid')->nullable()->after('transactionable_type');
                $table->index(['transactionable_type', 'transactionable_uuid'], "{$tableName}_transactionable_index");
            });

            // event_uuid is event-specific; non-event transactions leave it null.
            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('event_uuid')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex("{$tableName}_transactionable_index");
                $table->dropColumn(['transactionable_type', 'transactionable_uuid']);
            });
        }
    }
};
