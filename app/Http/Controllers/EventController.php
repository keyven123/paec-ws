<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\EventRepository;
use App\Http\Requests\Event\BrowseByCityRequest;
use App\Http\Requests\Event\CreateEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Requests\Event\UpdateEventAffiliateRequest;
use App\Http\Requests\Event\EventTicketCalendarRequest;
use App\Http\Requests\Event\ListEventRequest;
use App\Http\Requests\Event\ExportEventReportRequest;
use App\Http\Requests\Event\ExportOccupiedSeatsRequest;
use App\Http\Resources\EventResource;
use App\Exceptions\NoEventFoundException;
use App\Exceptions\UnauthorizedException;
use App\Helpers\GeneralHelper;
use App\Services\EventLocationService;
use App\Services\PaecOrganizationService;
use App\Http\Repositories\TicketRepository;
use App\Http\Resources\EventPublicResource;
use App\Http\Resources\EventShowPublicResource;
use App\Http\Resources\BrowseByCityLocationResource;
use App\Http\Repositories\UploadRepository;
use App\Http\Repositories\TransactionRepository;
use App\Http\Requests\Event\ArrangeFeatureEventRequest;
use App\Http\Requests\Event\UpdateTodayCutoffRequest;
use App\Models\AffiliateConversion;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function __construct(
        protected EventRepository $eventRepository,
        protected UploadRepository $uploadRepository,
        protected TransactionRepository $transactionRepository,
        protected TicketRepository $ticketRepository
    ) {
    }

    /**
     * Display a listing of events.
     * @param ListEventRequest $request
     * @return JsonResponse
     */
    public function index(ListEventRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $this->assertAffiliateCatalogListAccess($filters['affiliate_catalog'] ?? null);

        $perPage = $request->get('per_page', 15);
        $list = $this->eventRepository->getAll($filters);
        return EventResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Display a listing of published events.
     * @param ListEventRequest $request
     * @return JsonResponse
     */
    public function published(ListEventRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->eventRepository->getPublicEvents($request->validated());
        return EventPublicResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Display a listing of public events.
     * @param ListEventRequest $request
     * @return JsonResponse
     */
    public function publicEvents(ListEventRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $payload = $request->validated();

        $cacheVersion = Cache::get('public_events_cache_version', 1);

        $cacheKey = 'public_events_v' . $cacheVersion . '_' . md5(json_encode($payload) . '_' . $perPage);

        // cache for 12 hours
        try {
            $response = Cache::tags(['public_events'])->remember($cacheKey, 43200, function () use ($payload, $perPage) {
                $list = $this->eventRepository->getPublicEvents($payload);
                return EventPublicResource::collection($list->paginate($perPage))->response()->getData(true);
            });
        } catch (\Exception $e) {
            $response = Cache::remember($cacheKey, 43200, function () use ($payload, $perPage) {
                $list = $this->eventRepository->getPublicEvents($payload);
                return EventPublicResource::collection($list->paginate($perPage))->response()->getData(true);
            });
        }

        return response()->json($response);
    }

    /**
     * Browse-by-city cards grouped from published event locations.
     */
    public function publicBrowseByCity(BrowseByCityRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $type = $payload['type'];

        $cacheVersion = Cache::get('public_events_cache_version', 1);
        $cacheKey = 'public_browse_by_city_v' . $cacheVersion . '_' . md5(json_encode($payload));

        try {
            $response = Cache::tags(['public_events'])->remember($cacheKey, 43200, function () use ($payload, $type) {
                return $this->buildBrowseByCityResponse($payload, $type);
            });
        } catch (\Exception $e) {
            $response = Cache::remember($cacheKey, 43200, function () use ($payload, $type) {
                return $this->buildBrowseByCityResponse($payload, $type);
            });
        }

        return response()->json($response);
    }

    /**
     * Display a listing of upcoming events.
     * @param ListEventRequest $request
     * @return JsonResponse
     */
    public function upcoming(ListEventRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->eventRepository->getUpcoming($request->validated());
        return EventPublicResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created event.
     * @param CreateEventRequest $request
     * @return JsonResponse
     */
    public function store(CreateEventRequest $request): JsonResponse
    {
        DB::beginTransaction();
        $payload = $request->validated();
        $payload['slug'] = GeneralHelper::generateSlug($payload['event_name']);

        $portraitImage = $request->file('portrait_image');
        $featuredImage = $request->file('featured_image');
        $eventShowcase = $request->file('event_showcase');
        if (!empty($portraitImage)) {
            $portrait = $this->uploadRepository->create([
                'file' => $portraitImage,
                'collection' => 'portrait',
            ]);
        }
        if (!empty($featuredImage)) {
            $featured = $this->uploadRepository->create([
                'file' => $featuredImage,
                'collection' => 'featured',
            ]);
        }
        $showcaseUploads = [];
        if (!empty($eventShowcase)) {
            $showcaseFiles = is_array($eventShowcase) ? $eventShowcase : [$eventShowcase];
            foreach ($showcaseFiles as $index => $showcase) {
                $upload = $this->uploadRepository->create([
                    'file' => $showcase,
                    'collection' => 'showcase',
                    'order_number' => $index + 1,
                ]);
                $showcaseUploads[] = $upload->uuid;
            }
        }

        if (!isset($payload['organization_uuid']) || empty($payload['organization_uuid'])) {
            $payload['organization_uuid'] = auth('admin')->user()->organization_uuid
                ?? PaecOrganizationService::defaultOrganizationUuid();
        }

        if (isset($portrait) && !empty($portrait)) {
            $payload['portrait_image_uuid'] = $portrait->uuid;
        }
        if (isset($featured) && !empty($featured)) {
            $payload['featured_image_uuid'] = $featured->uuid;
        }
        if (isset($showcaseUploads) && !empty($showcaseUploads)) {
            $payload['event_showcase'] = $showcaseUploads;
        }
        if ($payload['event_type'] == Event::EVENT_TYPES['DAILY']) {
            $eventSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->first();
            $payload['event_section_uuid'] = $eventSection ? $eventSection->uuid : null;
        }
        $event = $this->eventRepository->create($payload);
        EventLocationService::ensureDefaultLocation($event->fresh());
        $this->uploadRepository->attachEventUploads(
            $event,
            $portrait ?? null,
            $featured ?? null,
            $showcaseUploads,
        );

        $schedules = $payload['schedules'] ?? [];
        if (!empty($schedules)) {
            foreach ($schedules as $schedule) {
                $sched = $event->schedules()->create([
                    'date_from' => $schedule['date_from'],
                    'date_to' => $schedule['date_to'],
                    'status' => $schedule['status'],
                ]);
                foreach ($schedule['time'] as $time) {
                    $scheduleTime = $sched->scheduleTimes()->create([
                        'time_start' => $time['time_start'],
                        'time_end' => $time['time_end'],
                        'status' => $time['status'],
                    ]);

                    $tickets = $payload['tickets'] ?? [];
                    if (!empty($tickets)) {
                        foreach ($tickets as $ticket) {
                            $event->eventTickets()->create([
                                'schedule_uuid' => $sched->uuid,
                                'schedule_time_uuid' => $scheduleTime->uuid,
                                'code' => strtolower($ticket['name']),
                                'name' => $ticket['name'],
                                'description' => $ticket['description'] ?? null,
                                'price' => $ticket['price'],
                                'max_ticket' => $ticket['max_ticket'],
                                'is_virtual' => ($ticket['is_virtual'] ?? 'false') === 'true',
                                'virtual_event_url' => $ticket['virtual_event_url'] ?? null,
                                'is_unlimited' => ($ticket['is_unlimited'] ?? 'false') === 'true',
                                'visit_policy' => $ticket['visit_policy'] ?? null,
                                'validity_days' => ($ticket['visit_policy'] ?? null) === 'flexible' ? (int) ($ticket['validity_days'] ?? 0) : null,
                                'available_from' => $payload['ticket_available_from'],
                                'available_to' => $payload['ticket_available_to'],
                            ]);
                        }
                    }
                }
            }
        }
        if (empty($schedules)) {
            $tickets = $payload['tickets'] ?? [];
            if (!empty($tickets)) {
                foreach ($tickets as $ticket) {
                    $event->eventTickets()->create([
                        'code' => strtolower(Str::slug($ticket['name'])),
                        'name' => $ticket['name'],
                        'description' => $ticket['description'] ?? null,
                        'price' => $ticket['price'],
                        'max_ticket' => $ticket['max_ticket'],
                        'is_virtual' => ($ticket['is_virtual'] ?? 'false') === 'true',
                        'virtual_event_url' => $ticket['virtual_event_url'] ?? null,
                        'is_unlimited' => ($ticket['is_unlimited'] ?? 'false') === 'true',
                        'visit_policy' => $ticket['visit_policy'] ?? 'priority',
                        'validity_days' => ($ticket['visit_policy'] ?? null) === 'flexible' ? (int) ($ticket['validity_days'] ?? 0) : null,
                        'available_from' => $payload['ticket_available_from'] ?? null,
                        'available_to' => $payload['ticket_available_to'] ?? null,
                    ]);
                }
            }
        }

        DB::commit();
        // Clear public events cache
        $this->clearPublicEventsCache();
        return (new EventResource($event->fresh()))->response()->setStatusCode(201);
    }

    /**
     * Display the specified event.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            return (new EventResource($event))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    public function getScannedAttendees(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'schedule_uuid' => ['nullable', 'uuid', 'exists:schedules,uuid'],
            'schedule_time_uuid' => ['nullable', 'uuid', 'exists:schedule_times,uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'scanned_date' => ['nullable', 'date'],
        ]);

        $perPage = min(100, max(1, (int) ($validated['per_page'] ?? 15)));

        $query = $this->ticketRepository
            ->getAll(['event_uuid' => $uuid])
            ->whereNotNull('used_at');

        $scannedDate = $validated['scanned_date'] ?? now()->format('Y-m-d');
        $timezone = (string) config('app.timezone', 'UTC');
        $start = Carbon::parse($scannedDate, $timezone)->startOfDay();
        $end = Carbon::parse($scannedDate, $timezone)->endOfDay();
        $query->whereBetween('used_at', [$start, $end]);

        if (!empty($validated['schedule_uuid'])) {
            $scheduleUuid = $validated['schedule_uuid'];
            $query->whereHas('transaction', function ($q) use ($scheduleUuid) {
                $q->where('schedule_uuid', $scheduleUuid);
            });
        }

        if (!empty($validated['schedule_time_uuid'])) {
            $scheduleTimeUuid = $validated['schedule_time_uuid'];
            $query->whereHas('transaction', function ($q) use ($scheduleTimeUuid) {
                $q->where('schedule_time_uuid', $scheduleTimeUuid);
            });
        }

        if (!empty($validated['search'])) {
            $term = '%' . addcslashes($validated['search'], '%_\\') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('ticket_number', 'like', $term)
                    ->orWhere('qr_code', 'like', $term)
                    ->orWhere('attendee_name', 'like', $term);
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Scanned attendees',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
    /**
     * Display the specified event.
     * @param string $identifier (can be uuid or slug)
     * @return JsonResponse
     */
    public function showEventPublic(string $identifier): JsonResponse
    {
        // Try to fetch by slug first, then fallback to uuid for backward compatibility
        try {
            $event = $this->eventRepository->fetchOrThrow('slug', $identifier);
        } catch (NoEventFoundException $e) {
            // If not found by slug, try uuid (for backward compatibility)
            $event = $this->eventRepository->fetchOrThrow('uuid', $identifier);
        }
        $event->load([
            'eventSection',
            'organization',
            'category',
            'portraitImage',
            'featuredImage',
            'eventLocations.organization',
        ]);
        return (new EventShowPublicResource($event))->response();
    }

    /**
     * Update the specified event.
     * @param UpdateEventRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateEventRequest $request, string $uuid): JsonResponse
    {
        try {
            DB::beginTransaction();
            $payload = $request->validated();
            $payload['slug'] = GeneralHelper::generateSlug($payload['slug']);
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);

            $portraitImage = $request->file('portrait_image');
            $featuredImage = $request->file('featured_image');
            $eventShowcase = $request->file('event_showcase');

            $portrait = null;
            $featured = null;
            $newShowcaseUploads = [];

            if (!empty($portraitImage)) {
                $portrait = $this->uploadRepository->create([
                    'file' => $portraitImage,
                    'collection' => 'portrait',
                ]);
                $payload['portrait_image_uuid'] = $portrait->uuid;
            }
            if (!empty($featuredImage)) {
                $featured = $this->uploadRepository->create([
                    'file' => $featuredImage,
                    'collection' => 'featured',
                ]);
                $payload['featured_image_uuid'] = $featured->uuid;
            }
            if (!empty($eventShowcase)) {
                $showcaseFiles = is_array($eventShowcase) ? $eventShowcase : [$eventShowcase];
                $showcaseUploads = [];
                foreach ($showcaseFiles as $index => $showcase) {
                    $upload = $this->uploadRepository->create([
                        'file' => $showcase,
                        'collection' => 'showcase',
                        'order_number' => $index + 1,
                    ]);
                    $showcaseUploads[] = $upload->uuid;
                }
                $newShowcaseUploads = $showcaseUploads;
                $existingShowcase = $event->event_showcase;
                if (is_string($existingShowcase)) {
                    $existingShowcase = json_decode($existingShowcase, true) ?? [];
                }
                if (!is_array($existingShowcase)) {
                    $existingShowcase = [];
                }
                $payload['event_showcase'] = array_values(
                    array_merge($existingShowcase, $showcaseUploads)
                );
            }

            $this->eventRepository->update($event, $payload);
            $this->uploadRepository->attachEventUploads(
                $event->fresh(),
                $portrait,
                $featured,
                $newShowcaseUploads,
            );
            DB::commit();

            // Clear public events cache
            $this->clearPublicEventsCache();

            $event->refresh()->load(['portraitImage', 'featuredImage', 'category', 'organization', 'eventSection']);

            return (new EventResource($event))->response();
        } catch (NoEventFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateAffiliateSettings(UpdateEventAffiliateRequest $request, string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $event->loadMissing('eventSection');
            $admin = auth('admin')->user();
            if (!$admin instanceof AdminUser) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $this->assertAffiliateSettingsUpdateAccess($event, $admin);
            if (!$admin->role->is_admin && $event->organization_uuid !== $admin->organization_uuid) {
                throw new UnauthorizedException('You cannot update this event.');
            }

            $validated = $request->validated();
            $event->affiliate_enabled = $validated['affiliate_enabled'];
            $event->affiliate_commission_percent = $validated['affiliate_enabled']
                ? ($validated['affiliate_commission_percent'] ?? null)
                : null;
            $event->affiliate_ends_at = $validated['affiliate_enabled']
                ? ($validated['affiliate_ends_at'] ?? null)
                : null;
            $event->save();

            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
            ], 404);
        }
    }

    public function submitForApproval(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->submitForApproval($event);
            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    public function requestForFeatured(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->requestForFeatured($event);
            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    public function cancelForFeatured(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->cancelForFeatured($event);
            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    public function cancelForApproval(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->cancelForApproval($event);
            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Approve the specified event.
     * @param string $uuid
     * @return JsonResponse
     */
    public function approve(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->approve($event);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Publish the specified event.
     * @param string $uuid
     * @return JsonResponse
     */
    public function publish(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->publish($event);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Unpublish the specified event.
     * @param string $uuid
     * @return JsonResponse
     */
    public function unpublish(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->unpublish($event);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Cancel the specified event.
     * @param string $uuid
     * @return JsonResponse
     */
    public function cancel(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->cancel($event);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Complete the specified event.
     * @param string $uuid
     * @return JsonResponse
     */
    public function complete(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->complete($event);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Feature the specified event.
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function feature(Request $request, string $uuid): JsonResponse
    {
        $validatedData = $request->validate([
            'featured_order' => ['nullable', 'integer', 'min:0'],
            'featured_from' => ['nullable', 'date'],
            'featured_until' => ['nullable', 'date', 'after_or_equal:featured_from'],
        ]);

        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->feature($event, $validatedData);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Unfeature the specified event.
     * @param string $uuid
     * @return JsonResponse
     */
    public function unfeature(string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->unfeature($event);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return (new EventResource($event->fresh()))->response();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Remove the specified event from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $this->eventRepository->delete($event);

            // Clear public events cache
            $this->clearPublicEventsCache();

            return $this->noContent();
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function getRecentPurchasedTickets(Request $request, string $uuid): JsonResponse
    {
        $tickets = $this->ticketRepository->getRecentPurchasedTickets(['event_uuid' => $uuid])->get();
        $activities = [];
        foreach ($tickets as $ticket) {
            $activities[] = [
                'type' => 'purchase',
                'timestamp' => Carbon::parse($ticket->transaction->created_at)->format('F d, Y H:i:'),
                'message' => "{$ticket->user->full_name} purchased tickets (Order: {$ticket->transaction->order_number})",
            ];
        }
        return response()->json([
            'success' => true,
            'message' => 'Recent purchased tickets activities',
            'activities' => $activities,
        ]);
    }

    public function getEventTicketCalendar(EventTicketCalendarRequest $request, string $uuid): JsonResponse
    {
        $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);

        if (! $this->eventRepository->isAmusementEvent($event)) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket calendar is only available for amusement events.',
            ], 403);
        }

        $year = (int) $request->validated('year');
        $month = (int) $request->validated('month');

        return response()->json([
            'success' => true,
            'message' => 'Event ticket calendar',
            'data' => $this->eventRepository->getEventTicketCalendar($uuid, $year, $month),
        ]);
    }

    public function getEventTicketsSold(string $uuid): JsonResponse
    {
        $eventTicketSold = $this->eventRepository->getEventTickets($uuid);
        $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
        $netRevenue = Transaction::where('event_uuid', $uuid)
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->sum('total_amount');
        $affiliateConversion = AffiliateConversion::where('event_uuid', $uuid)
            ->sum('commission_amount');
        $netRevenueAfterAffiliate = $netRevenue - $affiliateConversion;

        $locationSales = $event->eventLocations()
            ->with('organization')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(function ($location) use ($uuid, $event) {
                $paidTransactions = Transaction::query()
                    ->where('event_uuid', $uuid)
                    ->where('event_location_uuid', $location->uuid)
                    ->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);

                $location->total_orders = (clone $paidTransactions)->count();
                $location->total_amount = (float) (clone $paidTransactions)->sum('total_amount');
                $location->ticket_sold = $event->tickets()
                    ->where('event_location_uuid', $location->uuid)
                    ->where('status', '!=', GeneralConstants::TICKET_STATUSES['TRANSFERRED'])
                    ->whereHas('transaction', function ($query) {
                        $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
                    })
                    ->count();

                return $location;
            });

        return response()->json([
            'success' => true,
            'message' => 'Event ticket sold',
            'data' => $eventTicketSold,
            'location_sales' => \App\Http\Resources\EventLocationResource::collection($locationSales),
            'total_orders' => $event->transactions()->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->count(),
            'total_amount' => $netRevenueAfterAffiliate,
            'ticket_sold' => $event->tickets()->where('status', '!=', GeneralConstants::TICKET_STATUSES['TRANSFERRED'])->whereHas('transaction', function ($query) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
            })->count(),
        ]);
    }

    public function getEventStats(ListEventRequest $request): JsonResponse
    {
        $eventStats = $this->eventRepository->getEventStats($request->validated());
        return response()->json([
            'success' => true,
            'message' => 'Event stats',
            'data' => $eventStats,
        ]);
    }

    public function getFunStats(ListEventRequest $request): JsonResponse
    {
        $funStats = $this->eventRepository->getFunStats($request->validated());
        return response()->json([
            'success' => true,
            'message' => 'Fun stats',
            'data' => $funStats,
        ]);
    }

    public function export(ExportEventReportRequest $request, string $uuid): Response
    {
        $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
        $admin = auth('admin')->user();
        $includeAdminOnlyColumns = (bool) $admin?->role?->is_admin;
        [$start, $end] = $this->resolveExportDateRange($request->validated());
        $csvContent = $this->eventRepository->export($event, $includeAdminOnlyColumns, $start, $end);

        $cleanEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name);
        $cleanEventName = substr($cleanEventName, 0, 50);
        $fileName = 'purchasers_' . $cleanEventName . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }

    public function exportAttendeeRegistrationReport(ExportEventReportRequest $request, string $uuid): Response
    {
        $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
        [$start, $end] = $this->resolveExportDateRange($request->validated());
        $csvContent = $this->eventRepository->exportAttendeeRegistrationReport($event, $start, $end);

        $cleanEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name);
        $cleanEventName = substr($cleanEventName, 0, 50);
        $fileName = 'attendee_registration_report_' . $cleanEventName . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }

    public function exportUsedTickets(ExportEventReportRequest $request, string $uuid): Response
    {
        $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
        [$start, $end] = $this->resolveExportDateRange($request->validated());
        $csvContent = $this->ticketRepository->exportUsedTickets($event, $start, $end);

        $cleanEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name);
        $cleanEventName = substr($cleanEventName, 0, 50);
        $fileName = 'used_tickets_' . $cleanEventName . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }

    public function exportOccupiedSeats(ExportOccupiedSeatsRequest $request, string $uuid): \Illuminate\Http\Response
    {
        $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);

        $payload = $request->validated();
        $scheduleUuid = $payload['schedule_uuid'];
        $scheduleTimeUuid = $payload['schedule_time_uuid'];

        $csvContent = $this->ticketRepository->exportOccupiedSeats($event, $scheduleUuid, $scheduleTimeUuid);

        $cleanEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name);
        $cleanEventName = substr($cleanEventName, 0, 50);
        $fileName = 'occupied_seats_' . $cleanEventName . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }

    public function exportTickets(ExportEventReportRequest $request, string $uuid): Response
    {
        $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
        [$start, $end] = $this->resolveExportDateRange($request->validated());
        $csvContent = $this->ticketRepository->exportTickets($event, $start, $end);

        $cleanEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name);
        $cleanEventName = substr($cleanEventName, 0, 50);
        $fileName = 'tickets_' . $cleanEventName . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }

    public function arrangeFeaturedEvents(ArrangeFeatureEventRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $this->eventRepository->arrangeFeaturedEvents($validatedData);

        // Clear public events cache
        $this->clearPublicEventsCache();

        return response()->json([
            'success' => true,
            'message' => 'Featured events arrangement successfully updated',
        ]);
    }

    public function updateTodayCutoff(UpdateTodayCutoffRequest $request, string $uuid): JsonResponse
    {
        try {
            $event = $this->eventRepository->fetchOrThrow('uuid', $uuid);
            $cutoff = $request->validated()['today_cutoff_time'] ?? null;

            $event->update([
                'today_cutoff_time' => $cutoff ? $cutoff . ':00' : null,
            ]);

            $this->clearPublicEventsCache();

            return response()->json([
                'success' => true,
                'message' => 'Today cut-off time updated.',
                'data' => [
                    'today_cutoff_time' => $event->fresh()->formattedTodayCutoffTime(),
                ],
            ]);
        } catch (NoEventFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
            ], 404);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function buildBrowseByCityResponse(array $payload, string $type): array
    {
        $locations = $this->eventRepository->getBrowseByCityLocations($payload);
        $cities = $this->eventRepository->getBrowseByCityCities($type);

        return [
            'data' => [
                'cities' => $cities,
                'locations' => BrowseByCityLocationResource::collection($locations)->resolve(),
            ],
        ];
    }

    private function assertAffiliateCatalogListAccess(?string $affiliateCatalog): void
    {
        if ($affiliateCatalog === null) {
            if (!GeneralHelper::checkHasAccess('events-view')) {
                abort(403);
            }

            return;
        }

        if ($affiliateCatalog === 'fun') {
            if (!GeneralHelper::checkHasAccess('affiliate-funs-view')) {
                abort(403);
            }

            return;
        }

        if (!GeneralHelper::checkHasAccess('affiliate-events-view')) {
            abort(403);
        }
    }

    private function assertAffiliateSettingsUpdateAccess(Event $event, AdminUser $admin): void
    {
        $isFun = $event->eventSection?->name === EventSection::AMUSEMENT_SECTION;
        $permission = $isFun ? 'affiliate-funs-update' : 'affiliate-events-update';

        if (!$admin->hasPermission($permission)) {
            abort(403);
        }
    }

    /**
     * @param  array{start_date?: string|null, end_date?: string|null}  $validated
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveExportDateRange(array $validated): array
    {
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        if ($startDate === null || $endDate === null) {
            return [null, null];
        }

        return [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay(),
        ];
    }
}
