<?php

namespace App\Services\Checkout;

use App\Models\Transaction;
use App\Models\VenueInquiry;
use App\Services\VenueInquiryWorkflowService;
use Illuminate\Support\Facades\Log;

class VenueCheckoutHandler implements CheckoutHandlerInterface
{
    public function __construct(
        protected VenueInquiryWorkflowService $workflowService,
    ) {
    }

    public function morphAlias(): string
    {
        return 'venue_inquiry';
    }

    public function handlePaid(Transaction $transaction, bool $syncFulfillment = false): void
    {
        $inquiry = $transaction->transactionable;

        if (! $inquiry instanceof VenueInquiry) {
            Log::warning('Venue transaction paid without a linked inquiry', [
                'transaction_uuid' => $transaction->uuid,
                'transactionable_uuid' => $transaction->transactionable_uuid,
            ]);

            return;
        }

        $phase = $transaction->payment_data['venue_payment_phase']
            ?? VenueInquiry::PAYMENT_PHASE_DEPOSIT;

        try {
            if ($phase === VenueInquiry::PAYMENT_PHASE_BALANCE) {
                $this->workflowService->handleFullyPaid($inquiry, $transaction);
            } else {
                $this->workflowService->handleDepositPaid($inquiry, $transaction);
            }
        } catch (\Throwable $e) {
            Log::error('Venue checkout handler failed', [
                'inquiry_uuid' => $inquiry->uuid,
                'transaction_uuid' => $transaction->uuid,
                'phase' => $phase,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
