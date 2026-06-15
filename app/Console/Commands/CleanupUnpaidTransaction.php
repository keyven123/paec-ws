<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupUnpaidTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-unpaid-transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup unpaid transactions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cleanup of unpaid transactions...');

        DB::beginTransaction();
        $transactions = Transaction::where('created_at', '<', now()->subHours(24))
            ->where('payment_status', '!=', Transaction::PAYMENT_STATUS['PAID'])
            ->whereNull('paid_at')
            ->whereHas('tickets')
            ->get();

        $count = $transactions->count();
        $this->info("Found {$count} unpaid transaction(s) to cleanup.");

        foreach ($transactions as $transaction) {
            $ticketCount = $transaction->tickets()->count();

            $tickets = $transaction->tickets;
            foreach ($tickets as $ticket) {
                $ticket->delete();
            }

            $transaction->update([
                'status' => Transaction::STATUS['CANCELLED'],
                'payment_status' => Transaction::PAYMENT_STATUS['FAILED']
            ]);
            $this->info("Cleaned up transaction #{$transaction->uuid} with {$ticketCount} ticket(s).");
        }

        DB::commit();

        $this->info("Cleanup completed. Processed {$count} transaction(s).");
    }
}
