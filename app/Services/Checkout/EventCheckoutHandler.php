<?php

namespace App\Services\Checkout;

use App\Jobs\SuccessPaymentProcess;
use App\Models\Transaction;
use App\Services\AffiliateAttributionService;
use App\Services\MetaPixelService;
use App\Services\Platform\TransactionCommissionService;
use Illuminate\Support\Facades\Log;

/**
 * Checkout behaviour for event/ticket transactions.
 *
 * Encapsulates the post-payment side effects that were previously inlined in
 * TempTransactionController::completePayment so that non-event modules are not
 * forced through event-specific logic (affiliate conversion, commission ledger,
 * ticket delivery, Meta Pixel purchase tracking).
 */
class EventCheckoutHandler implements CheckoutHandlerInterface
{
    public function __construct(
        protected TransactionCommissionService $transactionCommissionService,
        protected MetaPixelService $metaPixelService,
    ) {
    }

    public function morphAlias(): string
    {
        return 'event';
    }

    public function handlePaid(Transaction $transaction, bool $syncFulfillment = false): void
    {
        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->transactionCommissionService->recordPaidTransaction(
            $transaction->load('event.organization')
        );

        if ($syncFulfillment) {
            SuccessPaymentProcess::dispatchSync($transaction->uuid);
        } else {
            SuccessPaymentProcess::dispatch($transaction->uuid);
        }

        $this->trackMetaPixelPurchase($transaction);
    }

    private function trackMetaPixelPurchase(Transaction $transaction): void
    {
        try {
            $user = $transaction->user;
            $userData = $user ? [
                'email' => $user->email ?? null,
                'phone' => $user->phone ?? null,
                'first_name' => $user->first_name ?? null,
                'last_name' => $user->last_name ?? null,
                'external_id' => $user->uuid ?? null,
            ] : [];

            $this->metaPixelService->trackPurchase($transaction, $userData);
        } catch (\Exception $e) {
            Log::error('Failed to track Meta Pixel purchase event', [
                'transaction_uuid' => $transaction->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
