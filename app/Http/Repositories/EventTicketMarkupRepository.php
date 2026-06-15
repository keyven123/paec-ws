<?php

namespace App\Http\Repositories;

use App\Exceptions\NoResourceFoundException;
use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EventTicketMarkupRepository
{
    /**
     * @return Collection<int, Event>
     */
    public function eventsWithTicketsForOrganization(string $organizationUuid): Collection
    {
        return Event::query()
            ->where('organization_uuid', $organizationUuid)
            ->with(['eventTickets' => function ($query) {
                $query->orderBy('display_order')->orderBy('name');
            }])
            ->orderBy('event_name')
            ->get(['uuid', 'event_name', 'status', 'organization_uuid']);
    }

    /**
     * @throws NoResourceFoundException
     */
    public function fetchTicketForOrganizationOrThrow(string $eventTicketUuid, string $organizationUuid): EventTicket
    {
        try {
            return EventTicket::query()
                ->where('uuid', $eventTicketUuid)
                ->whereHas('event', fn ($q) => $q->where('organization_uuid', $organizationUuid))
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new NoResourceFoundException('Event ticket not found for this organization');
        }
    }

    /**
     * @param  array{markup_type: string|null, markup_value: float|null}  $payload
     */
    public function updateMarkup(EventTicket $ticket, array $payload): EventTicket
    {
        $ticket->markup_type = $payload['markup_type'];
        $ticket->markup_value = $payload['markup_value'];
        $ticket->save();

        return $ticket->fresh();
    }
}
