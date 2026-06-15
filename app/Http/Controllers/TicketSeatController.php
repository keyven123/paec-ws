<?php

namespace App\Http\Controllers;

use App\Http\Repositories\TicketSeatRepository;
use App\Http\Requests\TicketSeat\CreateTicketSeatRequest;
use App\Http\Requests\TicketSeat\UpdateTicketSeatRequest;
use App\Http\Requests\TicketSeat\ListTicketSeatRequest;
use App\Http\Resources\TicketSeatResource;
use App\Exceptions\NoTicketSeatFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class TicketSeatController extends Controller
{
    public function __construct(protected TicketSeatRepository $ticketSeatRepository)
    {
    }

    /**
     * Display a listing of ticket seats.
     * @param ListTicketSeatRequest $request
     * @return JsonResponse
     */
    public function index(ListTicketSeatRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->ticketSeatRepository->getAll($request->validated());
        return TicketSeatResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created ticket seat.
     * @param CreateTicketSeatRequest $request
     * @return JsonResponse
     */
    public function store(CreateTicketSeatRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $ticketSeat = $this->ticketSeatRepository->create($payload);
        return (new TicketSeatResource($ticketSeat))->response()->setStatusCode(201);
    }

    /**
     * Display the specified ticket seat.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $ticketSeat = $this->ticketSeatRepository->fetchOrThrow('uuid', $uuid);
            return (new TicketSeatResource($ticketSeat))->response();
        } catch (NoTicketSeatFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket seat not found'
            ], 404);
        }
    }

    /**
     * Update the specified ticket seat.
     * @param UpdateTicketSeatRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateTicketSeatRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $ticketSeat = $this->ticketSeatRepository->fetchOrThrow('uuid', $uuid);
            $this->ticketSeatRepository->update($ticketSeat, $payload);
            return (new TicketSeatResource($ticketSeat->fresh()))->response();
        } catch (NoTicketSeatFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket seat not found'
            ], 404);
        }
    }

    /**
     * Remove the specified ticket seat from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $ticketSeat = $this->ticketSeatRepository->fetchOrThrow('uuid', $uuid);
            $this->ticketSeatRepository->delete($ticketSeat);
            return $this->noContent();
        } catch (NoTicketSeatFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket seat not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}
