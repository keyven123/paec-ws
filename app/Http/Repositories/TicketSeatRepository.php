<?php

namespace App\Http\Repositories;

use App\Exceptions\NoTicketSeatFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\TicketSeat;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;

class TicketSeatRepository
{
    /**
     * @param TicketSeat $ticketSeat
     */
    public function __construct(protected TicketSeat $ticketSeat)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->ticketSeat->with(['ticket.user', 'ticket.event', 'venueSeat.venue', 'creator', 'updater'])
            ->filters($filters)
            ->orderBy('col', 'asc')
            ->orderBy('row', 'asc')
            ->orderBy('seat_no', 'asc');
    }

    /**
     * Fetch ticket seat or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return TicketSeat
     * @throws NoTicketSeatFoundException
     */
    public function fetchOrThrow(string $key, string $value): TicketSeat
    {
        $ticketSeat = $this->ticketSeat->with(['ticket.user', 'ticket.event', 'venueSeat.venue', 'creator', 'updater'])
            ->where($key, $value)->first();

        if (is_null($ticketSeat)) {
            throw new NoTicketSeatFoundException();
        }

        return $ticketSeat;
    }

    /**
     * @param array $payload
     * @return TicketSeat
     */
    public function create(array $payload): TicketSeat
    {
        $ticketSeatPayload = GeneralHelper::unsetUnknownAndNullFields($payload, TicketSeat::DATA);
        return $this->ticketSeat->create($ticketSeatPayload);
    }

    /**
     * @param TicketSeat $ticketSeat
     * @param array $payload
     * @return bool|TicketSeat
     */
    public function update(TicketSeat $ticketSeat, array $payload): bool|TicketSeat
    {
        $ticketSeatPayload = GeneralHelper::unsetUnknownAndNullFields($payload, TicketSeat::DATA);
        return $ticketSeat->update($ticketSeatPayload);
    }

    /**
     * @param TicketSeat $ticketSeat
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(TicketSeat $ticketSeat): void
    {
        // Check if the associated ticket has been used
        if ($ticketSeat->ticket && $ticketSeat->ticket->used_at) {
            throw new UnauthorizedException('Cannot delete seat from a used ticket.');
        }
        
        $ticketSeat->delete();
    }
}
