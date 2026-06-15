<?php

namespace App\Http\Controllers;

use App\Exceptions\ActionNotAllowedException;
use App\Http\Repositories\EventTicketRepository;
use App\Http\Requests\EventTicket\BulkCreateEventTicketsRequest;
use App\Http\Requests\EventTicket\CreateEventTicketRequest;
use App\Http\Requests\EventTicket\DuplicateEventTicketRequest;
use App\Http\Requests\EventTicket\UpdateEventTicketRequest;
use App\Http\Requests\EventTicket\ListEventTicketRequest;
use App\Http\Resources\EventTicketResource;
use App\Exceptions\NoEventTicketFoundException;
use App\Http\Requests\Customer\ShowEventTicketRequest;
use App\Http\Resources\EventTicketPublicResource;
use App\Jobs\DeleteUserTicketCouponsJob;
use App\Jobs\UpdateUserTicketCouponsJob;
use App\Models\EventTicketCoupon;
use App\Support\EventTicketCodeGenerator;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EventTicketController extends Controller
{
    public function __construct(protected EventTicketRepository $eventTicketRepository)
    {
    }

    /**
     * Display a listing of event tickets.
     * @param ListEventTicketRequest $request
     * @return JsonResponse
     */
    public function index(ListEventTicketRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->eventTicketRepository->getAll($request->validated());
        return EventTicketResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created event ticket.
     * @param CreateEventTicketRequest $request
     * @return JsonResponse
     */
    public function store(CreateEventTicketRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $code = isset($payload['code']) ? trim((string) $payload['code']) : '';
        if ($code === '') {
            $payload['code'] = EventTicketCodeGenerator::generate($payload['event_uuid'], $payload['name'] ?? null);
        }
        $eventTicket = $this->eventTicketRepository->create($payload);

        if (!empty($payload['with_coupon']) && !empty($payload['coupons']) && is_array($payload['coupons'])) {
            $this->syncCoupons($eventTicket, $payload['coupons']);
        }

        $this->clearPublicEventsCache();
        return (new EventTicketResource($eventTicket->load('coupons')))->response()->setStatusCode(201);
    }

    /**
     * Create multiple event tickets in one transaction.
     */
    public function bulkStore(BulkCreateEventTicketsRequest $request): JsonResponse
    {
        $pack = $request->validatedTicketsPayload();
        $rows = $pack['tickets'];
        $eventUuid = $pack['event_uuid'];

        $created = DB::transaction(function () use ($rows, $eventUuid) {
            $out = [];
            foreach ($rows as $payload) {
                $code = isset($payload['code']) ? trim((string) $payload['code']) : '';
                if ($code === '') {
                    $payload['code'] = EventTicketCodeGenerator::generate($eventUuid, $payload['name'] ?? null);
                }
                $eventTicket = $this->eventTicketRepository->create($payload);
                if (! empty($payload['with_coupon']) && ! empty($payload['coupons']) && is_array($payload['coupons'])) {
                    $this->syncCoupons($eventTicket, $payload['coupons']);
                }
                $out[] = $eventTicket->fresh()->load('coupons');
            }

            return $out;
        });

        $this->clearPublicEventsCache();

        return EventTicketResource::collection(collect($created))->response()->setStatusCode(201);
    }

    /**
     * Display the specified event ticket.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $eventTicket = $this->eventTicketRepository->fetchOrThrow('uuid', $uuid);
            return (new EventTicketResource($eventTicket))->response();
        } catch (NoEventTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event ticket not found'
            ], 404);
        }
    }

    /**
     * Update the specified event ticket.
     * @param UpdateEventTicketRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateEventTicketRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $eventTicket = $this->eventTicketRepository->fetchOrThrow('uuid', $uuid);
            $this->eventTicketRepository->update($eventTicket, $payload);

            if (array_key_exists('with_coupon', $payload)) {
                if (empty($payload['with_coupon'])) {
                    dispatch(new DeleteUserTicketCouponsJob($eventTicket->coupons()->pluck('uuid')->toArray()));
                    $this->deleteAllCoupons($eventTicket);
                } else {
                    $this->updateCoupons($eventTicket, $payload['coupons'] ?? []);
                }
            }

            $this->clearPublicEventsCache();
            return (new EventTicketResource($eventTicket->fresh()->load('coupons')))->response();
        } catch (NoEventTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event ticket not found'
            ], 404);
        }
    }

    /**
     * Remove the specified event ticket from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     * @throws ActionNotAllowedException
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        $eventTicket = $this->eventTicketRepository->fetchOrThrow('uuid', $uuid);
        if ($eventTicket->sold_ticket > 0 || $eventTicket->transactionOrders()->count() > 0) {
            throw new ActionNotAllowedException('Cannot delete event ticket with sold tickets.');
        }
        $this->eventTicketRepository->delete($eventTicket);
        return $this->noContent();
    }

    public function duplicate(DuplicateEventTicketRequest $request): JsonResponse
    {
        try {
            $eventTicket = $this->eventTicketRepository->fetchOrThrow('uuid', $request->validated()['uuid']);
            $duplicate = $this->eventTicketRepository->duplicate($eventTicket);
            $this->clearPublicEventsCache();

            return (new EventTicketResource($duplicate))->response()->setStatusCode(201);
        } catch (NoEventTicketFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event ticket not found',
            ], 404);
        }
    }

    public function getEventTicketsPublic(ShowEventTicketRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['event_uuid'] = $request->input('uuid');
        $tickets = $this->eventTicketRepository->getAll($filters)->active()->get();
        return EventTicketPublicResource::collection($tickets)->response();
    }

    /**
     * Create coupons for a new event ticket (store only).
     *
     * @param \App\Models\EventTicket $eventTicket
     * @param array $names Array of coupon names
     * @return void
     */
    protected function syncCoupons($eventTicket, array $coupons): void
    {
        $userId = auth('api')->id();
        foreach (array_filter($coupons) as $coupon) {
            $name = is_string($coupon) ? $coupon : ($coupon['name'] ?? '');
            $onceOnly = is_array($coupon) ? ($coupon['once_only'] ?? false) : false;
            EventTicketCoupon::create([
                'event_ticket_uuid' => $eventTicket->uuid,
                'name' => $name,
                'once_only' => $onceOnly,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }
    }

    /**
     * Delete all coupons for an event ticket (e.g. when with_coupon is 0 or false).
     *
     * @param \App\Models\EventTicket $eventTicket
     * @return void
     */
    protected function deleteAllCoupons($eventTicket): void
    {
        $eventTicket->coupons()->delete();
    }

    /**
     * Sync coupons for an event ticket with the payload.
     * - Coupons in DB whose uuid is not in the payload are deleted.
     * - Items with uuid: update that coupon's name (must belong to this ticket).
     * - Items without uuid: create new coupon.
     *
     * @param \App\Models\EventTicket $eventTicket
     * @param array $coupons Array of { uuid?: string, name: string }
     * @return void
     */
    protected function updateCoupons($eventTicket, array $coupons): void
    {
        $userId = auth('api')->id();

        $uuidsInPayload = [];
        foreach ($coupons as $item) {
            $uuid = is_array($item) ? ($item['uuid'] ?? null) : null;
            if (!empty($uuid)) {
                $uuidsInPayload[] = $uuid;
            }
        }

        $deletedCouponIds = $eventTicket->coupons()->whereNotIn('uuid', $uuidsInPayload)->pluck('uuid')->toArray();
        $eventTicket->coupons()->whereNotIn('uuid', $uuidsInPayload)->delete();

        foreach ($coupons as $item) {
            $name = is_array($item) ? ($item['name'] ?? '') : (string) $item;
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $onceOnly = is_array($item) ? ($item['once_only'] ?? false) : false;
            $uuid = is_array($item) ? ($item['uuid'] ?? null) : null;
            if ($uuid) {
                $eventTicket->coupons()->where('uuid', $uuid)->update([
                    'name' => $name,
                    'once_only' => $onceOnly,
                    'updated_by' => $userId,
                ]);
            } else {
                EventTicketCoupon::create([
                    'event_ticket_uuid' => $eventTicket->uuid,
                    'name' => $name,
                    'once_only' => $onceOnly,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }
        }

        dispatch(new UpdateUserTicketCouponsJob($eventTicket->uuid));
        dispatch(new DeleteUserTicketCouponsJob($deletedCouponIds));
    }

    /**
     * Clear all public events cache by incrementing cache version
     * This works with all cache drivers (database, file, redis, memcached)
     * @return void
     */
    protected function clearPublicEventsCache(): void
    {
        // Try to use cache tags if supported (Redis, Memcached)
        try {
            Cache::tags(['public_events'])->flush();
        } catch (\Exception $e) {
            // If tags are not supported, increment cache version
            // This will invalidate all existing cache keys since they include the version
            $currentVersion = Cache::get('public_events_cache_version', 1);
            Cache::forever('public_events_cache_version', $currentVersion + 1);
        }
    }
}
