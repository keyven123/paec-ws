<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoVerifyPendingTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:auto-verify-pending-transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto verify pending transactions';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting auto verify pending transactions...');
        $tempTransactionController = resolve(\App\Http\Controllers\Customer\TempTransactionController::class);

        $transactions = Transaction::query()
            ->whereNotNull('payment_id')
            ->where('order_status', Transaction::ORDER_STATUS['PENDING'])
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $count = $transactions->count();

        $this->info("Found {$count} pending transaction(s) to auto verify.");

        $processCompleted = 0;
        $processStillPending = 0;
        $processFailed = 0;
        foreach ($transactions as $transaction) {
            $this->info("Starting to verify transaction #{$transaction->uuid}.");

            try {
                $response = $tempTransactionController->completePayment($transaction->uuid);
                $payload = (array) $response->getData(true);
                $isSuccessful = (bool) ($payload['success'] ?? false);
                $statusCode = $response->getStatusCode();
                $message = $payload['message'] ?? 'Unknown response from payment service.';

                if ($isSuccessful) {
                    $this->info("Verified transaction #{$transaction->uuid}.");
                    $processCompleted++;
                    continue;
                }

                if (($payload['message'] ?? '') === 'Payment is still pending') {
                    $processStillPending++;
                    $this->line("Transaction #{$transaction->uuid} is still pending on the gateway.");
                    continue;
                }

                if (($payload['message'] ?? '') === 'Payment failed') {
                    $processFailed++;
                    $this->warn("Transaction #{$transaction->uuid} was cancelled after gateway reported failure.");
                    continue;
                }

                $this->warn("Verification did not complete for transaction #{$transaction->uuid}. HTTP {$statusCode} - {$message}");
            } catch (\Throwable $throwable) {
                Log::error('Auto verify pending transaction failed', [
                    'transaction_uuid' => $transaction->uuid,
                    'payment_provider' => $transaction->payment_provider,
                    'error' => $throwable->getMessage(),
                ]);
                $this->error("Exception while verifying transaction #{$transaction->uuid}: {$throwable->getMessage()}");
            }
        }

        $this->info("Auto verify pending transactions completed. Verified {$processCompleted}, still pending {$processStillPending}, cancelled after gateway failure {$processFailed}.");
    }
}
