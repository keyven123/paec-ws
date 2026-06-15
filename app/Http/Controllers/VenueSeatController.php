<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\EventRepository;
use App\Http\Repositories\VenueSeatRepository;
use App\Http\Requests\VenueSeat\CreateVenueSeatRequest;
use App\Http\Requests\VenueSeat\UpdateVenueSeatRequest;
use App\Http\Requests\VenueSeat\ListVenueSeatRequest;
use App\Http\Resources\VenueSeatResource;
use App\Exceptions\NoVenueSeatFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Requests\VenueSeat\ShowVenueSeatRequest;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\Ticket;
use App\Models\Transaction;
use Generator;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class VenueSeatController extends Controller
{
    public function __construct(
        protected VenueSeatRepository $venueSeatRepository,
        protected EventRepository $eventRepository,
    ) {
    }

    /**
     * Display a listing of venue seats.
     * @param ListVenueSeatRequest $request
     * @return JsonResponse
     */
    public function index(ListVenueSeatRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->venueSeatRepository->getAll($request->validated());
        return VenueSeatResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created venue seat.
     * @param CreateVenueSeatRequest $request
     * @return JsonResponse
     */
    public function store(CreateVenueSeatRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $venueSeat = $this->venueSeatRepository->create($payload);
        return (new VenueSeatResource($venueSeat))->response()->setStatusCode(201);
    }

    /**
     * Display the specified venue seat.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $venueSeat = $this->venueSeatRepository->fetchOrThrow('uuid', $uuid);
            return (new VenueSeatResource($venueSeat))->response();
        } catch (NoVenueSeatFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue seat not found'
            ], 404);
        }
    }

    /**
     * Update the specified venue seat.
     * @param UpdateVenueSeatRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateVenueSeatRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $venueSeat = $this->venueSeatRepository->fetchOrThrow('uuid', $uuid);
            $this->venueSeatRepository->update($venueSeat, $payload);
            return (new VenueSeatResource($venueSeat->fresh()))->response();
        } catch (NoVenueSeatFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue seat not found'
            ], 404);
        }
    }

    /**
     * Remove the specified venue seat from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $venueSeat = $this->venueSeatRepository->fetchOrThrow('uuid', $uuid);
            $this->venueSeatRepository->delete($venueSeat);
            return $this->noContent();
        } catch (NoVenueSeatFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue seat not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function getVenueSeatsPublic(ShowVenueSeatRequest $request)
    {
        $payload = $request->validated();
        $schedule = Schedule::where('uuid', $payload['schedule_uuid'])->first();
        $occupiedSeats = Ticket::whereHas('transaction', function ($query) use ($payload) {
            $query->whereNotIn('status', [Transaction::PAYMENT_STATUS['FAILED'], Transaction::PAYMENT_STATUS['CANCELLED']]);
            if ($payload['schedule_uuid']) {
                $query->where('schedule_uuid', $payload['schedule_uuid']);
            }
            if ($payload['schedule_time_uuid']) {
                $query->where('schedule_time_uuid', $payload['schedule_time_uuid']);
            }
        })
        ->where('status', '!=', GeneralConstants::TICKET_STATUSES['CANCELLED'])
        ->where('event_uuid', $payload['uuid'])
        ->pluck('venue_seat_uuid');

        $event = Event::where('uuid', $payload['uuid'])->first();
        $blockedSeats = $event->blocked_seats ?? [];
        $occupiedOrBlockedUuids = array_values(array_unique(array_merge(
            $occupiedSeats->toArray(),
            $blockedSeats
        )));

        // Return ALL venue seats so the frontend can keep stable positions; mark availability per seat
        $venueSeats = $this->venueSeatRepository->getAll($request->validated())
            ->whereStatus(GeneralConstants::GENERAL_STATUSES['ACTIVE']);
        if ($schedule) {
            $venueSeats = $venueSeats->where('venue_uuid', $schedule->event->venue_uuid);
        }
        $venueSeats = $venueSeats->select('uuid', 'col', 'row', 'seat_no', 'category', 'color', 'status', 'order')
            ->get();

        return $venueSeats->map(function ($seat) use ($occupiedOrBlockedUuids) {
            $arr = $seat->toArray();
            $arr['is_available'] = ! in_array($seat->uuid, $occupiedOrBlockedUuids);
            return $arr;
        })->values();
    }

    public function getVenueSeatsPublicV2(ShowVenueSeatRequest $request)
    {
        $payload = $request->validated();
        $schedule = Schedule::where('uuid', $payload['schedule_uuid'])->first();
        $occupiedSeats = Ticket::whereHas('transaction', function ($query) use ($payload) {
            $query->whereNotIn('status', [Transaction::PAYMENT_STATUS['FAILED'], Transaction::PAYMENT_STATUS['CANCELLED']]);
            if ($payload['schedule_uuid']) {
                $query->where('schedule_uuid', $payload['schedule_uuid']);
            }
            if ($payload['schedule_time_uuid']) {
                $query->where('schedule_time_uuid', $payload['schedule_time_uuid']);
            }
        })
        ->where('status', '!=', GeneralConstants::TICKET_STATUSES['CANCELLED'])
        ->where('event_uuid', $payload['uuid'])
        ->pluck('venue_seat_uuid');

        $event = Event::where('uuid', $payload['uuid'])->first();
        $blockedSeats = $event->blocked_seats ?? [];
        $occupiedOrBlockedUuids = array_values(array_unique(array_merge(
            $occupiedSeats->toArray(),
            $blockedSeats
        )));

        // Return ALL venue seats so the frontend can keep stable positions; mark availability per seat
        $venueSeats = $this->venueSeatRepository->getAll($request->validated())
            ->whereStatus(GeneralConstants::GENERAL_STATUSES['ACTIVE']);
        if ($schedule) {
            $venueSeats = $venueSeats->where('venue_uuid', $schedule->event->venue_uuid);
        }
        $venueSeats = $venueSeats->select('uuid', 'col', 'row', 'seat_no', 'category', 'color', 'status', 'order')
            ->get();

        return $venueSeats->map(function ($seat) use ($occupiedOrBlockedUuids) {
            $arr = $seat->toArray();
            $arr['is_available'] = ! in_array($seat->uuid, $occupiedOrBlockedUuids);
            return $arr;
        })->values();
    }
}
