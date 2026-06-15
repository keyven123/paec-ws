<?php

namespace App\Jobs;

use App\Models\TicketCoupon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeleteUserTicketCouponsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $deletedCouponIds)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Deleted coupon ids', ['deletedCouponIds' => $this->deletedCouponIds]);
        if (count($this->deletedCouponIds) > 0) {
            TicketCoupon::whereIn('event_ticket_coupon_uuid', $this->deletedCouponIds)->delete();
        }
    }
}
