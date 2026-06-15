<?php

namespace App\Http\Controllers;

use App\Http\Repositories\TicketRepository;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\UpdateTicketRequest;
use App\Http\Requests\Ticket\UpgradeTicketRequest;
use App\Http\Requests\Ticket\ListTicketRequest;
use App\Http\Resources\TicketResource;
use App\Exceptions\NoTicketFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Requests\Ticket\AddTicketToUserRequest;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\EventTicket;
use App\Models\User;
use App\Notifications\PaymentSuccessfulNotification;
use Carbon\Carbon;

class TicketController extends Controller
{
    public function __construct(protected TicketRepository $ticketRepository)
    {
    }

    /**
     * Display a listing of tickets.
     * @param ListTicketRequest $request
     * @return JsonResponse
     */
    public function index(ListTicketRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        /** @var \Illuminate\Database\Eloquent\Builder $list */
        $list = $this->ticketRepository->getAll($request->validated());
        return TicketResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created ticket.
     * @param CreateTicketRequest $request
     * @return JsonResponse
     */
    public function store(CreateTicketRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $ticket = $this->ticketRepository->create($payload);
        return (new TicketResource($ticket))->response()->setStatusCode(201);
    }

    /**
     * Display the specified ticket.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid);
            return (new TicketResource($ticket))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        }
    }

    /**
     * Update the specified ticket.
     * @param UpdateTicketRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateTicketRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid);
            $this->ticketRepository->update($ticket, $payload);
            return (new TicketResource($ticket->fresh()))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        }
    }

    /**
     * Remove the specified ticket from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid);
            $this->ticketRepository->delete($ticket);
            return $this->noContent();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function addTicketToUser(AddTicketToUserRequest $request): Response|JsonResponse
    {
        $payload = $request->validated();
        $this->ticketRepository->addTicketToUser($payload);
        return response()->json([
            'success' => true,
            'message' => 'Ticket added to user successfully'
        ], 201);
    }

    /**
     * Mark ticket as used.
     * @param string $uuid
     * @return JsonResponse
     */
    public function markAsUsed(string $uuid): JsonResponse
    {
        try {
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid);
            $this->ticketRepository->markAsUsed($ticket);
            return (new TicketResource($ticket->fresh()))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    /**
     * Transfer ticket to another user.
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function transfer(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'user_uuid' => ['required', 'uuid', 'exists:users,uuid']
        ]);

        try {
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid);
            $user = User::query()->where('uuid', $request->user_uuid)->first();
            if (is_null($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            $this->ticketRepository->transferTicket($ticket, $user);
            return (new TicketResource($ticket->fresh()))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    /**
     * Get ticket details by QR code.
     * @param Request $request
     * @return JsonResponse
     */
    public function getTicketsDetailByQrCode(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => ['required', 'string'],
            'event_uuid' => ['nullable', 'uuid', 'exists:events,uuid']
        ]);

        try {
            $ticket = $this->ticketRepository->getByQrCode(
                $request->qr_code,
                $request->event_uuid
            );

            $restriction = $this->entryVisitDateRestriction($ticket)
                ?? $this->entryScheduleRestriction($ticket);
            if ($restriction) {
                return response()->json([
                    'success' => false,
                    'code' => $restriction['code'],
                    'message' => $restriction['message'],
                    'meta' => $restriction['meta'],
                ], 403);
            }
            return (new TicketResource($ticket))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found with the provided QR code'
            ], 404);
        }
    }

    /**
     * Confirm entry - mark ticket as used.
     * @param string $uuid
     * @return JsonResponse
     */
    public function confirmEntry(string $uuid): JsonResponse
    {
        try {
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid)->loadMissing([
                'user',
                'transaction.schedule',
                'transaction.scheduleTime',
                'transaction.transactionOrders',
                'eventTicket',
            ]);

            $restriction = $this->entryVisitDateRestriction($ticket)
                ?? $this->entryScheduleRestriction($ticket);
            if ($restriction) {
                return response()->json([
                    'success' => false,
                    'code' => $restriction['code'],
                    'message' => $restriction['message'],
                    'meta' => $restriction['meta'],
                ], 403);
            }
            $this->ticketRepository->confirmEntry($ticket);
            return (new TicketResource($ticket->fresh()))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    /**
     * Validate ticket entry window based on schedule and schedule time.
     * Rule: allow scan from 1 hour before start until end time.
     *
     * @return array{code:string,message:string,meta:array}|null
     */
    private function entryScheduleRestriction($ticket): ?array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);

        $transaction = $ticket->transaction;
        if (!$transaction) {
            return null;
        }

        $schedule = $transaction->schedule;
        if (!$schedule || !$schedule->date_from) {
            return null;
        }

        $scheduleTime = $transaction->scheduleTime;

        if ($scheduleTime && $scheduleTime->time_start && $scheduleTime->time_end) {
            $start = Carbon::parse($schedule->date_from->format('Y-m-d') . ' ' . $scheduleTime->time_start, $timezone);
            $end = Carbon::parse($schedule->date_from->format('Y-m-d') . ' ' . $scheduleTime->time_end, $timezone);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
        } else {
            $start = Carbon::parse($schedule->date_from->format('Y-m-d') . ' 00:00:00', $timezone);
            $dateTo = $schedule->date_to ?: $schedule->date_from;
            $end = Carbon::parse($dateTo->format('Y-m-d') . ' 23:59:59', $timezone);
        }

        $allowedFrom = $start->copy()->subHour();
        $allowedUntil = $end->copy();

        if ($now->lt($allowedFrom)) {
            return [
                'code' => 'schedule_not_started',
                'message' => 'Entry is not allowed yet. This ticket is for a later schedule.',
                'meta' => [
                    'now' => $now->toISOString(),
                    'schedule_start' => $start->toISOString(),
                    'schedule_end' => $end->toISOString(),
                    'allowed_from' => $allowedFrom->toISOString(),
                    'allowed_until' => $allowedUntil->toISOString(),
                ],
            ];
        }

        if ($now->gt($allowedUntil)) {
            return [
                'code' => 'schedule_ended',
                'message' => 'Entry is no longer allowed. This ticket schedule has ended.',
                'meta' => [
                    'now' => $now->toISOString(),
                    'schedule_start' => $start->toISOString(),
                    'schedule_end' => $end->toISOString(),
                    'allowed_from' => $allowedFrom->toISOString(),
                    'allowed_until' => $allowedUntil->toISOString(),
                ],
            ];
        }

        return null;
    }

    /**
     * Validate visit date for priority / date-of-visit tickets.
     * Flexible tickets may be scanned any day until valid_until.
     *
     * @return array{code:string,message:string,meta:array}|null
     */
    private function entryVisitDateRestriction($ticket): ?array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $today = Carbon::now($timezone)->startOfDay();

        $ticket->loadMissing([
            'user',
            'transaction.transactionOrders',
            'transaction.schedule',
            'eventTicket',
        ]);

        $visitPolicy = $ticket->visit_policy ?? $ticket->eventTicket?->visit_policy;

        $transaction = $ticket->transaction;
        $orderVisitDate = null;
        if ($transaction) {
            $order = $transaction->transactionOrders
                ->first(fn ($o) => $o->event_ticket_uuid === $ticket->event_ticket_uuid);
            if ($order?->valid_until) {
                $orderVisitDate = Carbon::parse($order->valid_until, $timezone)->startOfDay();
            }
        }

        $ticketVisitDate = $ticket->valid_until
            ? $ticket->valid_until->copy()->timezone($timezone)->startOfDay()
            : null;

        $visitDate = $ticketVisitDate ?? $orderVisitDate;
        $attendeeName = trim((string) ($ticket->attendee_name ?? ''));
        if ($attendeeName === '' && $ticket->user) {
            $attendeeName = trim($ticket->user->first_name . ' ' . $ticket->user->last_name);
        }

        $meta = [
            'today' => $today->format('Y-m-d'),
            'attendee_name' => $attendeeName !== '' ? $attendeeName : null,
        ];

        if ($visitPolicy === 'flexible') {
            $validUntil = $ticketVisitDate ?? $orderVisitDate;
            if ($validUntil && $today->gt($validUntil)) {
                return [
                    'code' => 'visit_expired',
                    'message' => 'This ticket has expired.',
                    'meta' => array_merge($meta, [
                        'valid_until' => $validUntil->format('Y-m-d'),
                    ]),
                ];
            }

            return null;
        }

        if ($visitDate && $visitDate->ne($today)) {
            return [
                'code' => 'visit_not_today',
                'message' => 'Your visit is not today.',
                'meta' => array_merge($meta, [
                    'visit_date' => $visitDate->format('Y-m-d'),
                ]),
            ];
        }

        return null;
    }

    public function upgrade(UpgradeTicketRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid);
            $targetEventTicket = EventTicket::query()
                ->with('coupons')
                ->where('uuid', $payload['ticket_uuid'])
                ->firstOrFail();
            $newTicket = $this->ticketRepository->upgrade(
                $ticket,
                $targetEventTicket,
                (float) $payload['amount']
            );
            $newTicket->loadMissing(['user', 'transaction']);
            if ($newTicket->user !== null && $newTicket->transaction !== null) {
                $newTicket->user->notify(
                    new PaymentSuccessfulNotification(
                        $newTicket->transaction->uuid,
                        PaymentSuccessfulNotification::CONTEXT_UPGRADE,
                    ),
                );
            }

            return (new TicketResource($newTicket->fresh(['user', 'transaction', 'event', 'eventTicket', 'ticketSeat'])))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        try {
            $ticket = $this->ticketRepository->fetchOrThrow('uuid', $uuid);
            $this->ticketRepository->cancel($ticket, $request->get('remarks'));
            return (new TicketResource($ticket->fresh()))->response();
        } catch (NoTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}
