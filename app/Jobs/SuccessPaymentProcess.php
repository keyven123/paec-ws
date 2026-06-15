<?php

namespace App\Jobs;

use App\Constants\GeneralConstants;
use App\Helpers\GeneralHelper;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\TicketSeat;
use App\Notifications\PaymentSuccessfulNotification;
use App\Services\AffiliateAttributionService;
use App\Services\MetaPixelService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SuccessPaymentProcess implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $transactionUuid)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $transaction = Transaction::whereUuid($this->transactionUuid)->firstOrFail();
        $event = $transaction->event;
        $event->increment('total_revenue', $transaction->total_amount);
        $event->increment('total_orders', 1);

        if ($transaction->promo_code_uuid) {
            $promoCode = PromoCode::whereUuid($transaction->promo_code_uuid)->firstOrFail();
            $promoCode->increment('used_count', 1);
        }

        $tickets = $transaction->tickets()->withTrashed()->get();
        foreach ($tickets as $ticket) {
            if (!is_null($ticket->venue_seat_uuid)) {
                TicketSeat::create([
                    'ticket_uuid' => $ticket->uuid,
                    'venue_uuid' => $ticket->event->venue_uuid,
                    'venue_seat_uuid' => $ticket->venue_seat_uuid,
                    'col' => $ticket->col,
                    'row' => $ticket->row,
                    'seat_no' => "{$ticket->col}-{$ticket->row}",
                    'category' => $ticket->venueSeat?->category ?? null,
                    'color' => $ticket->venueSeat?->color ?? null,
                ]);
            }

            $ticket->update([
                'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
                'qr_code' => GeneralHelper::generateQrCode($ticket, $event->ticket_prefix ?? 'QR_'),
                'deleted_at' => null,
            ]);
            $event->increment('ticket_sold', 1);
            $ticket->eventTicket->increment('sold_ticket', 1);
            $ticket->coupons()->update([
                'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
            ]);
        }

        // Record affiliate conversion if applicable
        try {
            AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);
        } catch (\Exception $e) {
            Log::error('Affiliate conversion recording failed in SuccessPaymentProcess', [
                'transaction_uuid' => $this->transactionUuid,
                'error' => $e->getMessage(),
            ]);
        }

        // Track Meta Pixel Purchase event
        try {
            $metaPixelService = app(MetaPixelService::class);

            // Prepare user data for tracking
            $user = $transaction->user;
            $userData = [];

            if ($user) {
                $userData = [
                    'email' => $user->email ?? null,
                    'phone' => $user->phone ?? null,
                    'first_name' => $user->first_name ?? null,
                    'last_name' => $user->last_name ?? null,
                    'external_id' => $user->uuid ?? null,
                ];
            }

            // Track purchase event
            $metaPixelService->trackPurchase($transaction, $userData);
        } catch (\Exception $e) {
            // Log error but don't fail the job if Meta Pixel tracking fails
            Log::error('Meta Pixel Purchase tracking failed in SuccessPaymentProcess', [
                'transaction_uuid' => $this->transactionUuid,
                'error' => $e->getMessage(),
            ]);
        }

        // Send receipt email after tickets have QR codes and coupons are activated (avoids race with queued mail).
        $transaction->loadMissing('user', 'event');
        if ($transaction->user !== null) {
            $transaction->user->notify(new PaymentSuccessfulNotification($transaction->uuid));

            // In-app notification so the customer sees the bell badge.
            $eventName = $transaction->event?->name ?? 'your event';
            app(NotificationService::class)->send(
                $transaction->user,
                'ticket_purchase',
                'Ticket Purchase Successful',
                "Your tickets for \"{$eventName}\" have been confirmed.",
                '/account/tickets',
                ['transaction_uuid' => $transaction->uuid],
            );
        }
    }
}
