<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Exceptions\NoEventFoundException;
use App\Http\Repositories\EventRepository;
use App\Http\Requests\EventLocation\StoreEventLocationRequest;
use App\Http\Requests\EventLocation\UpdateEventLocationRequest;
use App\Http\Resources\EventLocationResource;
use App\Models\EventLocation;
use App\Models\Ticket;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

class EventLocationController extends Controller
{
    public function __construct(
        protected EventRepository $eventRepository,
    ) {
    }

    public function index(string $eventUuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $eventUuid);
        } catch (NoEventFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
            ], 404);
        }

        $locations = EventLocation::query()
            ->with('organization')
            ->where('event_uuid', $event->uuid)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(function (EventLocation $location) use ($event) {
                $paidTransactions = Transaction::query()
                    ->where('event_uuid', $event->uuid)
                    ->where('event_location_uuid', $location->uuid)
                    ->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);

                $location->total_orders = (clone $paidTransactions)->count();
                $location->total_amount = (float) (clone $paidTransactions)->sum('total_amount');
                $location->ticket_sold = Ticket::query()
                    ->where('event_uuid', $event->uuid)
                    ->where('event_location_uuid', $location->uuid)
                    ->where('status', '!=', GeneralConstants::TICKET_STATUSES['TRANSFERRED'])
                    ->whereHas('transaction', function ($query) {
                        $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
                    })
                    ->count();

                return $location;
            });

        return EventLocationResource::collection($locations)->response();
    }

    public function store(StoreEventLocationRequest $request, string $eventUuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $eventUuid);
        } catch (NoEventFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
            ], 404);
        }

        $payload = $request->validated();
        $payload['event_uuid'] = $event->uuid;
        $payload['organization_uuid'] = $payload['organization_uuid'] ?? $event->organization_uuid;
        $payload['is_active'] = $payload['is_active'] ?? true;
        $payload['sort_order'] = $payload['sort_order']
            ?? ((int) EventLocation::query()->where('event_uuid', $event->uuid)->max('sort_order') + 1);

        $location = EventLocation::create($payload);

        return (new EventLocationResource($location->load('organization')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateEventLocationRequest $request, string $eventUuid, string $locationUuid): JsonResponse
    {
        try {
            $this->eventRepository->fetchOrThrow('uuid', $eventUuid);
        } catch (NoEventFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
            ], 404);
        }

        $location = EventLocation::query()
            ->where('event_uuid', $eventUuid)
            ->where('uuid', $locationUuid)
            ->first();

        if (! $location) {
            return response()->json([
                'success' => false,
                'message' => 'Location not found',
            ], 404);
        }

        $location->update($request->validated());

        return (new EventLocationResource($location->fresh('organization')))->response();
    }

    public function destroy(string $eventUuid, string $locationUuid): JsonResponse
    {
        try {
            $this->eventRepository->fetchOrThrow('uuid', $eventUuid);
        } catch (NoEventFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
            ], 404);
        }

        $location = EventLocation::query()
            ->where('event_uuid', $eventUuid)
            ->where('uuid', $locationUuid)
            ->first();

        if (! $location) {
            return response()->json([
                'success' => false,
                'message' => 'Location not found',
            ], 404);
        }

        $activeCount = EventLocation::query()
            ->where('event_uuid', $eventUuid)
            ->where('is_active', true)
            ->count();

        if ($activeCount <= 1 && $location->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'At least one active location is required for this activity.',
            ], 422);
        }

        $hasSales = Transaction::query()
            ->where('event_location_uuid', $location->uuid)
            ->exists();

        if ($hasSales) {
            $location->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Location deactivated because it has existing sales.',
                'data' => new EventLocationResource($location->fresh('organization')),
            ]);
        }

        $location->delete();

        return response()->json([
            'success' => true,
            'message' => 'Location deleted successfully.',
        ]);
    }
}
