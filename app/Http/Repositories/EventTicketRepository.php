<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoEventTicketFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\EventTicket;
use App\Models\EventTicketCoupon;
use App\Helpers\GeneralHelper;
use App\Support\EventTicketCodeGenerator;
use Illuminate\Contracts\Database\Eloquent\Builder;

class EventTicketRepository
{
    /**
     * @param EventTicket $eventTicket
     */
    public function __construct(protected EventTicket $eventTicket)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->eventTicket->with(['schedule', 'scheduleTime', 'coupons'])
            ->filters($filters)
            ->orderBy('display_order', 'asc')
            ->orderBy('price', 'desc');
    }

    /**
     * Fetch event ticket or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return EventTicket
     * @throws NoEventTicketFoundException
     */
    public function fetchOrThrow(string $key, string $value): EventTicket
    {
        $eventTicket = $this->eventTicket->with(['event', 'scheduleTime.schedule', 'creator', 'updater', 'coupons'])
            ->where($key, $value)->first();

        if (is_null($eventTicket)) {
            throw new NoEventTicketFoundException();
        }

        return $eventTicket;
    }

    /**
     * @param array $payload
     * @return EventTicket
     */
    public function create(array $payload): EventTicket
    {
        $eventTicketPayload = array_diff_key($payload, array_flip(['with_coupon', 'coupons']));
        $eventTicketPayload = GeneralHelper::unsetUnknownAndNullFields($eventTicketPayload, EventTicket::DATA);
        return $this->eventTicket->create($eventTicketPayload);
    }

    /**
     * @param EventTicket $eventTicket
     * @param array $payload
     * @return bool|EventTicket
     */
    public function update(EventTicket $eventTicket, array $payload): bool|EventTicket
    {
        $eventTicketPayload = array_diff_key($payload, array_flip(['with_coupon', 'coupons']));
        $eventTicketPayload = GeneralHelper::unsetUnknown($eventTicketPayload, EventTicket::DATA);

        // Validate bundle_tickets to ensure it doesn't include itself
        if (isset($eventTicketPayload['bundle_tickets']) && is_array($eventTicketPayload['bundle_tickets'])) {
            // Remove self from bundle_tickets if present
            $eventTicketPayload['bundle_tickets'] = array_filter(
                $eventTicketPayload['bundle_tickets'],
                fn($ticketUuid) => $ticketUuid !== $eventTicket->uuid
            );
        }

        return $eventTicket->update($eventTicketPayload);
    }

    /**
     * @param EventTicket $eventTicket
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(EventTicket $eventTicket): void
    {
        // Add any business logic for deletion here
        // For example, prevent deletion if ticket has been sold

        $eventTicket->delete();
    }

    /**
     * @param EventTicket $eventTicket
     * @return EventTicket
     */
    public function duplicate(EventTicket $eventTicket): EventTicket
    {
        $attributes = $eventTicket->only(EventTicket::DATA);
        unset($attributes['sold_ticket']);

        $attributes['code'] = EventTicketCodeGenerator::generate(
            $eventTicket->event_uuid,
            $eventTicket->name
        );
        $attributes['name'] = $eventTicket->name . ' duplicate';
        $attributes['status'] = GeneralConstants::GENERAL_STATUSES['INACTIVE'];
        $attributes['sold_ticket'] = 0;

        $duplicate = $this->eventTicket->create($attributes);

        foreach ($eventTicket->coupons as $coupon) {
            EventTicketCoupon::create([
                'event_ticket_uuid' => $duplicate->uuid,
                'name' => $coupon->name,
                'once_only' => $coupon->once_only,
                'created_by' => auth('api')->id(),
                'updated_by' => auth('api')->id(),
            ]);
        }

        return $duplicate->fresh()->load(['schedule', 'scheduleTime', 'coupons']);
    }
}
