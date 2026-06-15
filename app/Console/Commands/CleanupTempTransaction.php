<?php

namespace App\Console\Commands;

use App\Models\TempTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupTempTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-temp-transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired temp transactions (seat holds after 10 minutes, others after 1 hour)';

    private const SEAT_HOLD_MINUTES = 10;

    private const DEFAULT_HOLD_MINUTES = 60;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $seatCutoff = now()->subMinutes(self::SEAT_HOLD_MINUTES);
        $defaultCutoff = now()->subMinutes(self::DEFAULT_HOLD_MINUTES);
        $deleted = 0;

        TempTransaction::query()
            ->with(['tempTransactionOrders', 'event'])
            ->where('created_at', '<', $seatCutoff)
            ->orderBy('created_at')
            ->chunkById(100, function ($tempTransactions) use ($seatCutoff, $defaultCutoff, &$deleted) {
                DB::transaction(function () use ($tempTransactions, $seatCutoff, $defaultCutoff, &$deleted) {
                    foreach ($tempTransactions as $tempTransaction) {
                        $isExpired = $tempTransaction->hasSeatReservation()
                            ? $tempTransaction->created_at < $seatCutoff
                            : $tempTransaction->created_at < $defaultCutoff;

                        if (! $isExpired) {
                            continue;
                        }

                        $tempTransaction->tempTransactionOrders()->delete();
                        $tempTransaction->delete();
                        $deleted++;
                    }
                });
            });

        $this->info("Deleted {$deleted} expired temp transaction(s).");

        return self::SUCCESS;
    }
}
