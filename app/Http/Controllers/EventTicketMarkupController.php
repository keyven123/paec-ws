<?php

namespace App\Http\Controllers;

use App\Exceptions\NoResourceFoundException;
use App\Http\Repositories\EventTicketMarkupRepository;
use App\Http\Requests\EventTicketMarkup\UpdateEventTicketMarkupRequest;
use App\Http\Resources\EventTicketMarkupResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventTicketMarkupController extends Controller
{
    public function __construct(protected EventTicketMarkupRepository $eventTicketMarkupRepository)
    {
    }

    public function indexByOrganization(string $organizationUuid): JsonResponse
    {
        $events = $this->eventTicketMarkupRepository
            ->eventsWithTicketsForOrganization($organizationUuid);

        $data = $events->map(function (Event $event) {
            return [
                'uuid' => $event->uuid,
                'event_name' => $event->event_name,
                'status' => $event->status,
                'event_tickets' => EventTicketMarkupResource::collection($event->eventTickets),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function update(UpdateEventTicketMarkupRequest $request, string $uuid): JsonResponse
    {
        $validated = $request->validated();

        try {
            $ticket = $this->eventTicketMarkupRepository->fetchTicketForOrganizationOrThrow(
                $uuid,
                $validated['organization_uuid'],
            );

            $markupType = $validated['markup_type'] ?? null;
            $markupValue = isset($validated['markup_value']) ? (float) $validated['markup_value'] : null;

            if ($markupType === null || $markupType === '') {
                $markupType = null;
                $markupValue = null;
            }

            $updated = $this->eventTicketMarkupRepository->updateMarkup($ticket, [
                'markup_type' => $markupType,
                'markup_value' => $markupValue,
            ]);

            return response()->json([
                'data' => (new EventTicketMarkupResource($updated))->resolve(),
            ]);
        } catch (NoResourceFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Event ticket not found for this organization',
            ], 404);
        }
    }
}
