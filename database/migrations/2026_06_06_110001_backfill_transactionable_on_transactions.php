<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that share the polymorphic transaction header.
     */
    private array $tables = ['transactions', 'temp_transactions'];

    /**
     * Morph alias for existing event-based transactions. Must match the entry
     * registered in AppServiceProvider::boot()'s Relation::morphMap().
     */
    private string $eventMorphAlias = 'event';

    /**
     * Run the migrations.
     *
     * Every existing transaction belongs to an event, so point its polymorphic
     * subject at the event it already references.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasColumn($tableName, 'transactionable_type')) {
                continue;
            }

            DB::table($tableName)
                ->whereNotNull('event_uuid')
                ->whereNull('transactionable_type')
                ->update([
                    'transactionable_type' => $this->eventMorphAlias,
                    'transactionable_uuid' => DB::raw('event_uuid'),
                ]);
        }
    }

    /**
     * Reverse the migrations — clears the morph columns this backfill set.
     * The source-of-truth event_uuid column is left untouched.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasColumn($tableName, 'transactionable_type')) {
                continue;
            }

            DB::table($tableName)
                ->where('transactionable_type', $this->eventMorphAlias)
                ->update([
                    'transactionable_type' => null,
                    'transactionable_uuid' => null,
                ]);
        }
    }
};
