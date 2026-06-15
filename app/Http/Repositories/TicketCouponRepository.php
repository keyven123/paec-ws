<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Models\TicketCoupon;
use Illuminate\Contracts\Database\Eloquent\Builder;

class TicketCouponRepository
{
    public function __construct(protected TicketCoupon $ticketCoupon)
    {
    }

    /**
     * Get all ticket coupons for a user (e.g. "My Coupons" list), ordered by latest first.
     *
     * @param string $userUuid
     * @param array $filters
     * @return Builder
     */
    public function getMyCoupons(string $userUuid, array $filters): Builder
    {
        return $this->ticketCoupon
            ->where('status', '!=', GeneralConstants::TICKET_COUPON_STATUSES['PENDING'])
            ->with(['event', 'eventTicketCoupon', 'ticket'])
            ->where('user_uuid', $userUuid)
            ->filters($filters)
            ->orderBy('created_at', 'desc');
    }

    public function getAll(array $filters): Builder
    {
        $query = $this->ticketCoupon
            ->with(['user', 'ticket', 'event', 'eventTicketCoupon', 'scannedBy'])
            ->filters($filters);

        if (!empty($filters['event_uuid'])) {
            $query->where('event_uuid', $filters['event_uuid']);
        }

        if (!empty($filters['schedule_uuid'])) {
            $scheduleUuid = $filters['schedule_uuid'];
            $query->whereHas('ticket.transaction', function ($q) use ($scheduleUuid) {
                $q->where('schedule_uuid', $scheduleUuid);
            });
        }

        if (!empty($filters['schedule_time_uuid'])) {
            $scheduleTimeUuid = $filters['schedule_time_uuid'];
            $query->whereHas('ticket.transaction', function ($q) use ($scheduleTimeUuid) {
                $q->where('schedule_time_uuid', $scheduleTimeUuid);
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        return $query->orderBy($sortBy, $sortOrder);
    }

    public function getByQrCode(array $payload): ?TicketCoupon
    {
        return $this->ticketCoupon
            ->status(GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'])
            ->where('qr_code', $payload['qr_code'])
            ->where('event_uuid', $payload['event_uuid'])
            ->with(['user', 'ticket', 'event', 'eventTicketCoupon'])
            ->first();
    }

    public function getByUuid(string $uuid): ?TicketCoupon
    {
        return $this->ticketCoupon
            ->where('uuid', $uuid)
            ->with(['user', 'ticket', 'event', 'eventTicketCoupon'])
            ->first();
    }

    public function confirmClaimed(TicketCoupon $coupon): bool
    {
        return $coupon->update([
            'status' => GeneralConstants::TICKET_COUPON_STATUSES['CLAIMED'],
            'claimed_at' => now(),
            'scanned_by' => auth('admin')->user()?->uuid ?? null
        ]);
    }
}
