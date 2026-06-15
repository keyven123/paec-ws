<?php

namespace App\Jobs;

use App\Constants\GeneralConstants;
use App\Helpers\GeneralHelper;
use App\Models\EventTicket;
use App\Models\TicketCoupon;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateUserTicketCouponsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $eventTicketUuid)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $eventTicket = EventTicket::where('uuid', $this->eventTicketUuid)->first();
        if (!$eventTicket) {
            return;
        }

        $tickets = $eventTicket->tickets()->get();
        $eventTicketCoupons = $eventTicket->coupons()->get();

        foreach (($tickets ?? collect()) as $ticket) {
            $ticketCoupons = $ticket->coupons()->get();
            foreach (($eventTicketCoupons ?? collect()) as $eventTicketCoupon) {
                $ticketCoupon = $ticketCoupons->firstWhere('event_ticket_coupon_uuid', $eventTicketCoupon->uuid);
                if (!$ticketCoupon) {
                    $ticket->coupons()->create([
                        'user_uuid' => $ticket->user_uuid,
                        'ticket_uuid' => $ticket->uuid,
                        'event_uuid' => $eventTicket->event_uuid,
                        'event_ticket_coupon_uuid' => $eventTicketCoupon->uuid,
                        'name' => $eventTicketCoupon->name,
                        'qr_code' => GeneralHelper::generateQrCode(new TicketCoupon(), 'COUPON_'),
                        'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
                    ]);
                }

                if ($ticketCoupon && $ticketCoupon->name !== $eventTicketCoupon->name) {
                    $ticketCoupon->update([
                        'name' => $eventTicketCoupon->name,
                    ]);
                }
            }
        }
    }
}
