<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoVerifyPendingTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:auto-verify-pending-transaction
                            {--date= : Limit to transactions created on this date (Y-m-d, app timezone)}
                            {--today : Limit to transactions created today (app timezone)}
                            {--include-cancelled : Also re-check cancelled/failed orders for gateway push-through payments}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto verify pending (and optionally cancelled) transactions against their payment gateway';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $includeCancelled = (bool) $this->option('include-cancelled');
        $dateOption = $this->option('date');
        if ($this->option('today')) {
            $dateOption = now(config('app.timezone', 'Asia/Manila'))->toDateString();
        }

        $this->info($includeCancelled
            ? 'Starting auto verify for pending and cancelled transactions...'
            : 'Starting auto verify pending transactions...');

        $tempTransactionController = resolve(\App\Http\Controllers\Customer\TempTransactionController::class);

        $paymentStatuses = [Transaction::PAYMENT_STATUS['PENDING']];
        if ($includeCancelled) {
            $paymentStatuses[] = Transaction::PAYMENT_STATUS['CANCELLED'];
            $paymentStatuses[] = Transaction::PAYMENT_STATUS['FAILED'];
        }

        $query = Transaction::query()
            ->whereNotNull('payment_id')
            ->whereIn('payment_status', $paymentStatuses);

        if (is_string($dateOption) && $dateOption !== '') {
            $day = Carbon::parse($dateOption, (string) config('app.timezone', 'Asia/Manila'));
            $query->whereBetween('created_at', [
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
            ]);
            $this->info('Filtering to transactions created on '.$day->toDateString().'.');
        } else {
            // Rolling window for the frequent scheduler (every 5 minutes).
            $query
                ->where('order_status', Transaction::ORDER_STATUS['PENDING'])
                ->where('created_at', '>=', now()->subHours(24));
        }

        $transactions = $query->orderBy('created_at')->get();
        $count = $transactions->count();

        $this->info("Found {$count} transaction(s) to auto verify.");

        $processCompleted = 0;
        $processStillPending = 0;
        $processFailed = 0;
        $processNoPayment = 0;

        foreach ($transactions as $transaction) {
            $this->info("Starting to verify transaction #{$transaction->uuid} ({$transaction->payment_status}).");

            try {
                $response = $tempTransactionController->verifyPendingPayment($transaction->uuid);
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

                if (str_contains((string) ($payload['message'] ?? ''), 'Gateway confirms no payment')) {
                    $processNoPayment++;
                    $this->line("Transaction #{$transaction->uuid}: gateway confirms no payment was taken.");
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

        $this->info(
            "Auto verify completed. Verified {$processCompleted}, still pending {$processStillPending}, "
            ."cancelled after gateway failure {$processFailed}, gateway unpaid {$processNoPayment}."
        );
    }
}
