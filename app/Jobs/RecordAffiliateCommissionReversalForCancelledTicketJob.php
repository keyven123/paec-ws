<?php

namespace App\Jobs;

use App\Models\AffiliateConversion;
use App\Models\Ticket;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordAffiliateCommissionReversalForCancelledTicketJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $ticketUuid)
    {
    }

    /**
     * Reverse affiliate commission when an admin cancels a ticket from a referred paid order.
     * Allocation is proportional to this ticket's (price − discount) share of all tickets on the transaction.
     */
    public function handle(): void
    {
        $ticket = Ticket::where('uuid', $this->ticketUuid)->first();
        if (!$ticket) {
            return;
        }

        $transaction = $ticket->transaction;
        if (!$transaction || $transaction->payment_status !== Transaction::PAYMENT_STATUS['PAID']) {
            return;
        }

        if (!$transaction->affiliate_partner_uuid) {
            return;
        }

        try {
            DB::transaction(function () use ($ticket, $transaction) {
                $existingReversal = AffiliateConversion::query()
                    ->where('ticket_uuid', $ticket->uuid)
                    ->where('entry_type', AffiliateConversion::ENTRY_TYPE_REVERSAL)
                    ->lockForUpdate()
                    ->exists();

                if ($existingReversal) {
                    return;
                }

                $credit = AffiliateConversion::query()
                    ->where('transaction_uuid', $transaction->uuid)
                    ->where('entry_type', AffiliateConversion::ENTRY_TYPE_CREDIT)
                    ->lockForUpdate()
                    ->first();

                if (!$credit) {
                    return;
                }

                $siblings = Ticket::query()
                    ->where('transaction_uuid', $transaction->uuid)
                    ->get();

                $lineNet = function (Ticket $t): float {
                    return max(0, (float) $t->price - (float) $t->discount);
                };

                $totalNet = (float) $siblings->sum(fn (Ticket $t) => $lineNet($t));
                $ticketNet = $lineNet($ticket);

                $creditAmount = (float) $credit->commission_amount;
                $sumReversals = (float) AffiliateConversion::query()
                    ->where('transaction_uuid', $transaction->uuid)
                    ->where('entry_type', AffiliateConversion::ENTRY_TYPE_REVERSAL)
                    ->sum('commission_amount');
                $remaining = round($creditAmount + $sumReversals, 2);

                if ($remaining <= 0) {
                    return;
                }

                if ($totalNet > 0 && $ticketNet > 0) {
                    $reversalAmount = -round($creditAmount * ($ticketNet / $totalNet), 2);
                } else {
                    $count = max(1, $siblings->count());
                    $reversalAmount = -round($creditAmount / $count, 2);
                }

                if (abs($reversalAmount) > $remaining + 0.009) {
                    $reversalAmount = -round($remaining, 2);
                }

                if (abs($reversalAmount) < 0.01) {
                    return;
                }

                AffiliateConversion::create([
                    'partner_user_uuid' => $credit->partner_user_uuid,
                    'transaction_uuid' => $transaction->uuid,
                    'entry_type' => AffiliateConversion::ENTRY_TYPE_REVERSAL,
                    'ticket_uuid' => $ticket->uuid,
                    'event_uuid' => $credit->event_uuid,
                    'order_total' => $ticketNet > 0 ? $ticketNet : max(0, (float) $transaction->total_amount / max(1, $siblings->count())),
                    'commission_percent' => $credit->commission_percent,
                    'commission_amount' => $reversalAmount,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Affiliate commission reversal failed', [
                'ticket_uuid' => $ticket->uuid,
                'transaction_uuid' => $ticket->transaction_uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
