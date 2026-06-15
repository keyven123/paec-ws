<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ListCustomerTicketRequest;
use App\Http\Repositories\TicketRepository;
use App\Http\Requests\Ticket\TransferMyActiveTicketRequest;
use App\Http\Requests\Ticket\UpdateMyTicketRequest;
use App\Http\Resources\TicketResource;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function __construct(
        protected TicketRepository $ticketRepository,
    ) {
    }

    public function getMyTickets(ListCustomerTicketRequest $request): JsonResponse
    {
        $perPage = min(10, max(1, (int) $request->get('per_page', 10)));
        $payload = $request->validated();
        $tickets = $this->ticketRepository->getSpecificTicketByUser($payload);

        return TicketResource::collection($tickets->paginate($perPage))->response();
    }

    public function transferMyTicketByEmail(TransferMyActiveTicketRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $ticket = $this->ticketRepository->fetchOrThrowByUser('uuid', $payload['uuid'], $request->user()->uuid);
        $this->ticketRepository->transferTicketByEmail($ticket, $payload['email'], $request->user()->uuid);
        return (new TicketResource($ticket->fresh()))->response();
    }

    public function updateMyTicket(UpdateMyTicketRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $ticket = $this->ticketRepository->fetchOrThrowByUser('uuid', $payload['uuid'], $request->user()->uuid);
        $this->ticketRepository->update($ticket, $payload);
        return (new TicketResource($ticket->fresh()))->response();
    }

    public function downloadTicket(Request $request, string $uuid): JsonResponse
    {
        try {
            $ticket = $this->ticketRepository->fetchOrThrowByUser('uuid', $uuid, $request->user()->uuid);

            // Prevent download if already downloaded
            if ($ticket->is_downloaded) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket has already been downloaded'
                ], 400);
            }

            // Mark ticket as downloaded
            $this->ticketRepository->downloadTicket($ticket);

            return response()->json([
                'success' => true,
                'message' => 'Ticket marked as downloaded successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Ticket download failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'uuid' => $uuid
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark ticket as downloaded.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function useTicket(Request $request, string $uuid): JsonResponse
    {
        try {
            $ticket = $this->ticketRepository->fetchOrThrowByUser('uuid', $uuid, $request->user()->uuid);

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found or not accessible'
                ], 404);
            }

            // Mark ticket as used
            $this->ticketRepository->markAsUsedForCustomer($ticket);

            return response()->json([
                'success' => true,
                'message' => 'Ticket marked as used successfully',
                'ticket' => new TicketResource($ticket->fresh())
            ], 200);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            Log::error('Ticket use failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'uuid' => $uuid
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?? 'Failed to mark ticket as used.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
