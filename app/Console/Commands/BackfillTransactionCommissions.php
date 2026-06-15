<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Services\Platform\TransactionCommissionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfills the transaction_commissions table from existing paid transactions.
 *
 * Idempotent — relies on the firstOrCreate inside
 * {@see TransactionCommissionService::recordPaidTransaction()} keyed on
 * (accountable_type, accountable_id, transaction_type), so it can be re-run
 * safely after partial runs or after new rate datasets are seeded.
 *
 * Additional modes:
 *   --update-columns   Back-fills markup_amount and tax_and_fees on existing
 *                      'transaction' rows that were created before those columns
 *                      were added (migration 2026_06_03_130000).
 *   --backfill-refunds Creates 'refund' ledger rows for every Transaction that
 *                      has status = 'refunded'. These rows are used by
 *                      AdminPlatformPnLService for the cross-month clawback
 *                      aggregate without a PHP cursor loop.
 */
class BackfillTransactionCommissions extends Command
{
    protected $signature = 'app:backfill-transaction-commissions
        {--dry-run : Print what would be processed without writing rows.}
        {--chunk=500 : Number of transactions to load per cursor chunk.}
        {--since= : Only process transactions whose paid_at (or created_at fallback) is on/after this date (YYYY-MM-DD).}
        {--update-columns : UPDATE existing transaction rows to populate markup_amount and tax_and_fees (requires migration 2026_06_03_130000).}
        {--backfill-refunds : Create refund ledger rows for all refunded transactions (idempotent).}';

    protected $description = 'Compute and populate transaction_commissions rows for every paid transaction with total_amount > 0.';

    public function handle(TransactionCommissionService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $since = $this->option('since');

        if ($this->option('update-columns')) {
            return $this->runUpdateColumns($isDryRun, $chunk);
        }

        if ($this->option('backfill-refunds')) {
            return $this->runBackfillRefunds($service, $isDryRun, $chunk);
        }

        $sinceDate = null;
        if ($since !== null && $since !== '') {
            try {
                $sinceDate = Carbon::parse($since)->startOfDay();
            } catch (\Throwable $e) {
                $this->error("Invalid --since value '{$since}'. Use YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        $query = Transaction::query()
            ->where('total_amount', '>', 0)
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);

        if ($sinceDate !== null) {
            $query->whereRaw('COALESCE(paid_at, created_at) >= ?', [$sinceDate]);
        }

        $total = (clone $query)->count();

        if ($isDryRun) {
            $this->warn('[dry-run] No rows will be written.');
        }

        $this->info(sprintf(
            'Backfilling transaction_commissions for %d candidate transaction(s)%s%s.',
            $total,
            $sinceDate ? " since {$sinceDate->toDateString()}" : '',
            $isDryRun ? ' (dry-run)' : ''
        ));

        if ($total === 0) {
            return self::SUCCESS;
        }

        $created = 0;
        $alreadyExisted = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query
            ->with(['event.organization'])
            ->orderBy('created_at')
            ->chunkById($chunk, function ($transactions) use (
                $service,
                $isDryRun,
                &$created,
                &$alreadyExisted,
                &$skipped,
                &$failed,
                $bar
            ) {
                foreach ($transactions as $transaction) {
                    /** @var Transaction $transaction */
                    try {
                        $existing = TransactionCommission::query()
                            ->where('accountable_type', Transaction::class)
                            ->where('accountable_id', $transaction->uuid)
                            ->where('transaction_type', TransactionCommission::TYPE['TRANSACTION'])
                            ->exists();

                        if ($existing) {
                            $alreadyExisted++;
                            $bar->advance();

                            continue;
                        }

                        if ($isDryRun) {
                            // Service guards still apply (free, unpaid, zero) —
                            // count this as the would-be outcome without writing.
                            if ($this->wouldBeRecorded($transaction)) {
                                $created++;
                            } else {
                                $skipped++;
                            }
                            $bar->advance();

                            continue;
                        }

                        $record = $service->recordPaidTransaction($transaction);
                        if ($record !== null) {
                            $created++;
                        } else {
                            $skipped++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error('Backfill transaction commission failed', [
                            'transaction_uuid' => $transaction->uuid,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $bar->advance();
                }
            }, 'uuid', 'uuid');

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Created', 'Already existed', 'Skipped (free/zero/unpaid)', 'Failed', 'Total candidates'],
            [[$created, $alreadyExisted, $skipped, $failed, $total]]
        );

        if ($failed > 0) {
            $this->warn("Completed with {$failed} failure(s). See storage/logs/laravel.log for details.");

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * UPDATE existing 'transaction' ledger rows to populate markup_amount and
     * tax_and_fees by joining back to the transactions table.
     */
    private function runUpdateColumns(bool $isDryRun, int $chunk): int
    {
        $this->info('Mode: --update-columns');

        $total = (int) DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM transaction_commissions
              WHERE transaction_type = ? AND (markup_amount = 0 OR tax_and_fees = 0)',
            [TransactionCommission::TYPE['TRANSACTION']]
        )?->cnt;

        $this->info("Found {$total} rows with unpopulated markup_amount / tax_and_fees.");

        if ($total === 0) {
            $this->info('Nothing to update.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn("[dry-run] Would update {$total} row(s).");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $updated = 0;

        // Chunk by the commission table's PK to avoid large UPDATE locks.
        TransactionCommission::query()
            ->where('transaction_type', TransactionCommission::TYPE['TRANSACTION'])
            ->where(function ($q) {
                $q->where('markup_amount', 0)->orWhere('tax_and_fees', 0);
            })
            ->select('uuid', 'transaction_uuid')
            ->chunkById($chunk, function ($rows) use (&$updated, $bar) {
                foreach ($rows as $row) {
                    if ($row->transaction_uuid === null) {
                        $bar->advance();
                        continue;
                    }
                    $tx = Transaction::query()
                        ->select('uuid', 'markup_amount', 'tax_amount')
                        ->where('uuid', $row->transaction_uuid)
                        ->first();
                    if ($tx === null) {
                        $bar->advance();
                        continue;
                    }
                    TransactionCommission::query()
                        ->where('uuid', $row->uuid)
                        ->update([
                            'markup_amount' => round((float) ($tx->markup_amount ?? 0), 2),
                            'tax_and_fees' => round((float) ($tx->tax_amount ?? 0), 2),
                        ]);
                    $updated++;
                    $bar->advance();
                }
            }, 'uuid', 'uuid');

        $bar->finish();
        $this->newLine(2);
        $this->info("Updated {$updated} row(s).");

        return self::SUCCESS;
    }

    /**
     * Create 'refund' ledger rows for all refunded Transactions that don't yet
     * have one. Pairs each refund transaction with its original transaction so
     * original_paid_at is accurate for cross-month clawback detection.
     */
    private function runBackfillRefunds(TransactionCommissionService $service, bool $isDryRun, int $chunk): int
    {
        $this->info('Mode: --backfill-refunds');

        $total = (int) Transaction::query()
            ->where('status', Transaction::STATUS['REFUNDED'])
            ->count();

        $this->info("Found {$total} refunded transaction(s).");

        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn("[dry-run] Would process {$total} refunded transaction(s).");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $created = 0;
        $existed = 0;
        $failed = 0;

        // Group by payment_order_id so we can find the original paid transaction.
        Transaction::query()
            ->where('status', Transaction::STATUS['REFUNDED'])
            ->orderBy('created_at')
            ->chunkById($chunk, function ($refundTxs) use (
                $service,
                &$created,
                &$existed,
                &$failed,
                $bar
            ) {
                foreach ($refundTxs as $refundTx) {
                    try {
                        $alreadyExists = TransactionCommission::query()
                            ->where('accountable_type', Transaction::class)
                            ->where('accountable_id', $refundTx->uuid)
                            ->where('transaction_type', TransactionCommission::TYPE['REFUND'])
                            ->exists();

                        if ($alreadyExists) {
                            $existed++;
                            $bar->advance();
                            continue;
                        }

                        // Find the original paid transaction by payment_order_id.
                        $originalPaidAt = null;
                        if ($refundTx->payment_order_id !== null) {
                            $originalTx = Transaction::query()
                                ->where('payment_order_id', $refundTx->payment_order_id)
                                ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                                ->where('status', '!=', Transaction::STATUS['REFUNDED'])
                                ->select('paid_at')
                                ->first();
                            $originalPaidAt = $originalTx?->paid_at?->toIso8601String();
                        }

                        $record = $service->recordRefundedTransaction($refundTx, $originalPaidAt);
                        if ($record !== null) {
                            $created++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error('Backfill refund commission failed', [
                            'transaction_uuid' => $refundTx->uuid,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $bar->advance();
                }
            }, 'uuid', 'uuid');

        $bar->finish();
        $this->newLine(2);
        $this->table(
            ['Created', 'Already existed', 'Failed', 'Total'],
            [[$created, $existed, $failed, $total]]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Mirrors the early-return guards in TransactionCommissionService so that
     * --dry-run reports an accurate "would be recorded" count.
     */
    private function wouldBeRecorded(Transaction $transaction): bool
    {
        if ($transaction->payment_status !== Transaction::PAYMENT_STATUS['PAID']) {
            return false;
        }

        $provider = strtolower((string) $transaction->payment_provider);
        if ($provider === '' || $provider === 'free') {
            return false;
        }

        if ((float) $transaction->total_amount <= 0.0) {
            return false;
        }

        return true;
    }
}
