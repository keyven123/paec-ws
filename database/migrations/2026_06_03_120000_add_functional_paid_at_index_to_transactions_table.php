<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a functional (expression) index on COALESCE(paid_at, created_at) to the
 * transactions table.
 *
 * The platform P&L service filters paid transactions with:
 *
 *   WHERE COALESCE(paid_at, created_at) BETWEEN ? AND ?
 *
 * Because the column expression is wrapped in COALESCE(), MySQL cannot use the
 * existing individual indexes on paid_at or created_at — it falls back to a full
 * table scan. On large datasets this caused 20-second response times and
 * production timeouts.
 *
 * MySQL 8.0.13+ supports functional key parts, which let the optimizer use an
 * index over an expression. This migration creates one for COALESCE(paid_at,
 * created_at) so that the BETWEEN range filter is sargable.
 *
 * A composite with (payment_status, status) is also included to cover the
 * typical query shape:
 *
 *   WHERE payment_status = 'paid'
 *     AND status != 'refunded'
 *     AND COALESCE(paid_at, created_at) BETWEEN ? AND ?
 *
 * The migration is a no-op (skipped gracefully) when the server version does not
 * support functional indexes (< 8.0.13) so it is safe in local dev environments
 * that may still run MySQL 5.7.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'transactions_pnl_paid_coalesce_idx';

    public function up(): void
    {
        if (! $this->supportsExpressionIndexes()) {
            return;
        }

        // Drop if somehow left over from a failed previous run.
        if ($this->indexExists()) {
            DB::statement('ALTER TABLE `transactions` DROP INDEX `'.self::INDEX_NAME.'`');
        }

        // Functional composite index covering both filter columns and the date expression.
        // Parentheses around the COALESCE(...) expression are required for MySQL functional key parts.
        DB::statement(
            'ALTER TABLE `transactions` ADD INDEX `'.self::INDEX_NAME.'`'
            .' (`payment_status`, `status`, (COALESCE(`paid_at`, `created_at`)))'
        );
    }

    public function down(): void
    {
        if (! $this->supportsExpressionIndexes()) {
            return;
        }

        if ($this->indexExists()) {
            DB::statement('ALTER TABLE `transactions` DROP INDEX `'.self::INDEX_NAME.'`');
        }
    }

    /**
     * MySQL supports functional key parts from 8.0.13.
     * Returns false on MySQL < 8.0.13 so the migration is a safe no-op there.
     */
    private function supportsExpressionIndexes(): bool
    {
        try {
            $version = DB::selectOne('SELECT VERSION() AS v')?->v ?? '';
            // Strip any suffix like "-MariaDB" or "-log".
            preg_match('/^(\d+)\.(\d+)\.(\d+)/', (string) $version, $m);
            $major = (int) ($m[1] ?? 0);
            $minor = (int) ($m[2] ?? 0);
            $patch = (int) ($m[3] ?? 0);

            // MariaDB does not support MySQL-style functional key parts in ALTER TABLE.
            if (str_contains(strtolower($version), 'mariadb')) {
                return false;
            }

            return ($major > 8)
                || ($major === 8 && $minor > 0)
                || ($major === 8 && $minor === 0 && $patch >= 13);
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexExists(): bool
    {
        $rows = DB::select(
            "SHOW INDEX FROM `transactions` WHERE Key_name = ?",
            [self::INDEX_NAME]
        );

        return count($rows) > 0;
    }
};
