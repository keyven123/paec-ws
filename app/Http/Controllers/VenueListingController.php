<?php

namespace App\Http\Controllers;

use App\Exceptions\NoVenueInquiryFoundException;
use App\Exceptions\NoVenueListingFoundException;
use App\Helpers\GeneralHelper;
use App\Http\Repositories\VenueInquiryRepository;
use App\Http\Repositories\VenueListingRepository;
use App\Http\Requests\VenueListing\CreateVenueListingRequest;
use App\Http\Requests\VenueListing\ListVenueInquiriesRequest;
use App\Http\Requests\VenueListing\ListVenueListingRequest;
use App\Http\Requests\VenueListing\StoreVenueInquiryRequest;
use App\Http\Repositories\ChatRepository;
use App\Http\Repositories\UploadRepository;
use App\Http\Requests\VenueListing\RequestVenueDepositRequest;
use App\Http\Requests\VenueListing\SendVenueFinalBillingRequest;
use App\Http\Requests\VenueListing\SendVenueProposalRequest;
use App\Http\Requests\VenueListing\UpdateVenueInquiryRequest;
use App\Http\Requests\VenueListing\UpdateVenueListingRequest;
use App\Http\Resources\VenueInquiryResource;
use App\Http\Resources\VenueListingDashboardResource;
use App\Http\Resources\VenueListingPublicDetailResource;
use App\Http\Resources\VenueListingPublicResource;
use App\Http\Resources\VenueListingResource;
use App\Services\Chat\ChatService;
use App\Services\NotificationService;
use App\Services\VenueInquiryWorkflowService;
use App\Services\VenueListingAvailabilityService;
use Illuminate\Support\Facades\Notification;
use App\Models\AdminUser;
use App\Models\ChatThread;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Notifications\VenueInquirySubmittedNotification;
use App\Support\VenueListingDefaults;
use App\Support\VenueListingPackageHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VenueListingController extends Controller
{
    public function __construct(
        protected VenueListingRepository $venueListingRepository,
        protected VenueInquiryRepository $venueInquiryRepository,
        protected NotificationService $notificationService,
        protected VenueInquiryWorkflowService $workflowService,
        protected UploadRepository $uploadRepository,
        protected ChatRepository $chatRepository,
        protected ChatService $chatService,
        protected VenueListingAvailabilityService $venueListingAvailabilityService,
    ) {
    }

    /**
     * @param ListVenueListingRequest $request
     * @return JsonResponse
     */
    public function index(ListVenueListingRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $request->get('per_page', 15);

        $adminUser = auth('admin')->user();
        if ($adminUser && !$adminUser->role?->is_admin && $adminUser->organization_uuid) {
            $filters['organization_uuid'] = $adminUser->organization_uuid;
        }

        $list = $this->venueListingRepository->getAll($filters);

        return VenueListingResource::collection($list->paginate($perPage))->response();
    }

    /**
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        $adminUser = auth('admin')->user();
        $organizationUuid = (!$adminUser?->role?->is_admin && $adminUser?->organization_uuid)
            ? $adminUser->organization_uuid
            : null;

        return response()->json([
            'data' => $this->venueListingRepository->getAdminStats($organizationUuid),
        ]);
    }

    /**
     * @param ListVenueListingRequest $request
     * @return JsonResponse
     */
    public function publicListings(ListVenueListingRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 50);
        $list = $this->venueListingRepository->getPublicListings($request->validated());

        return VenueListingPublicResource::collection($list->paginate($perPage))->response();
    }

    /**
     * @param CreateVenueListingRequest $request
     * @return JsonResponse
     */
    public function store(CreateVenueListingRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $adminUser = auth('admin')->user();
        if ($adminUser && !$adminUser->role?->is_admin && $adminUser->organization_uuid) {
            $payload['organization_uuid'] = $adminUser->organization_uuid;
        }

        if (empty($payload['organization_uuid'])) {
            return response()->json([
                'success' => false,
                'message' => 'Organization is required.',
                'errors' => ['organization_uuid' => ['Please select an organization.']],
            ], 422);
        }

        if (empty($payload['slug']) && !empty($payload['name'])) {
            $payload['slug'] = GeneralHelper::generateSlug($payload['name']);
        }

        if (empty($payload['category'])) {
            $payload['category'] = $this->resolveCategory($payload['venue_type'] ?? '');
        }

        if (empty($payload['packages'])) {
            $payload = array_merge($payload, $this->defaultVenuePackages($payload['price_per_event'] ?? 0));
        }

        $payload = VenueListingDefaults::applyMissing($payload);

        $venueListing = $this->venueListingRepository->create($payload);
        $venueListing->load(['organization', 'featuredImage', 'gallery']);

        return (new VenueListingResource($venueListing))->response()->setStatusCode(201);
    }

    /**
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $venueListing = $this->venueListingRepository->fetchOrThrow('uuid', $uuid);
            $venueListing->load([
                'organization',
                'featuredImage',
                'gallery',
            ]);
            $venueListing->loadCount([
                'inquiries as confirmed_inquiries_count' => fn ($query) => $query->whereIn(
                    'status',
                    [
                        VenueInquiry::STATUSES['FULLY_PAID'],
                        VenueInquiry::STATUSES['COMPLETED'],
                    ],
                ),
            ]);
            $venueListing->setAttribute(
                'inquiry_status_counts',
                $this->venueInquiryRepository->statusCountsForVenue($venueListing->uuid),
            );

            return (new VenueListingDashboardResource($venueListing))->response();
        } catch (NoVenueListingFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue listing not found',
            ], 404);
        }
    }

    /**
     * @param string $slug
     * @return JsonResponse
     */
    public function showPublic(string $slug): JsonResponse
    {
        try {
            $venueListing = $this->venueListingRepository->fetchPublicBySlug($slug);

            return (new VenueListingPublicDetailResource($venueListing))->response();
        } catch (NoVenueListingFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue not found',
            ], 404);
        }
    }

    /**
     * @param UpdateVenueListingRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateVenueListingRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $venueListing = $this->venueListingRepository->fetchOrThrow('uuid', $uuid);
            $payload = VenueListingDefaults::applyMissing($payload, $venueListing);
            $this->venueListingRepository->update($venueListing, $payload);
            $venueListing->load(['organization', 'featuredImage', 'gallery']);

            return (new VenueListingResource($venueListing->fresh(['featuredImage', 'gallery'])))->response();
        } catch (NoVenueListingFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue listing not found',
            ], 404);
        }
    }

    /**
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $venueListing = $this->venueListingRepository->fetchOrThrow('uuid', $uuid);
            $this->venueListingRepository->delete($venueListing);

            return $this->noContent();
        } catch (NoVenueListingFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue listing not found',
            ], 404);
        }
    }

    /**
     * @param StoreVenueInquiryRequest $request
     * @param string $slug
     * @return JsonResponse
     */
    public function storeInquiry(StoreVenueInquiryRequest $request, string $slug): JsonResponse
    {
        try {
            $venueListing = $this->venueListingRepository->fetchPublicBySlug($slug);
            $payload = $request->validated();
            $initialChatMessage = trim((string) ($payload['initial_chat_message'] ?? ''));
            unset($payload['initial_chat_message']);

            if (! empty($payload['event_date'])
                && $this->venueListingAvailabilityService->isDateUnavailable(
                    $venueListing,
                    (string) $payload['event_date'],
                )) {
                throw ValidationException::withMessages([
                    'event_date' => ['This date is not available for new inquiries. Please choose another date.'],
                ]);
            }

            $payload['venue_listing_uuid'] = $venueListing->uuid;
            $payload['user_uuid'] = auth('api')->user()?->uuid;
            $payload['status'] = VenueInquiry::STATUSES['NEW'];

            $inquiry = VenueInquiry::create($payload);
            $venueListing->increment('inquiries_count');

            if ($initialChatMessage !== '' && $inquiry->user_uuid !== null) {
                $this->sendInitialInquiryChatMessage($inquiry, $initialChatMessage);
            }

            $inquiry->load(['venueListing.organization']);
            $organization = $inquiry->venueListing?->organization;
            if ($organization !== null && !empty($organization->email)) {
                $organization->notify(new VenueInquirySubmittedNotification($inquiry->uuid));
            }

            // Notify all merchant staff in the venue's organization.
            if ($organization !== null) {
                $senderName = $inquiry->full_name ?? 'A customer';
                $venueUrl   = "/general-admin/venues/{$venueListing->uuid}?openInquiry={$inquiry->uuid}";

                $this->notificationService->sendToOrganization(
                    $organization->uuid,
                    'new_inquiry',
                    "{$senderName} has sent an inquiry",
                    "New venue inquiry for \"{$venueListing->name}\".",
                    $venueUrl,
                    ['inquiry_uuid' => $inquiry->uuid, 'venue_listing_uuid' => $venueListing->uuid],
                );
            }

            return (new VenueInquiryResource($inquiry))->response()->setStatusCode(201);
        } catch (NoVenueListingFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue not found',
            ], 404);
        }
    }

    /**
     * @param ListVenueInquiriesRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function listInquiries(ListVenueInquiriesRequest $request, string $uuid): JsonResponse
    {
        try {
            $venueListing = $this->venueListingRepository->fetchOrThrow('uuid', $uuid);
            $filters = $request->validated();
            $perPage = (int) ($filters['per_page'] ?? 10);

            $paginator = $this->venueInquiryRepository->paginateForVenue(
                $venueListing->uuid,
                $filters,
                $perPage,
            );

            return VenueInquiryResource::collection($paginator)->additional([
                'status_counts' => $this->venueInquiryRepository->statusCountsForVenue($venueListing->uuid),
            ])->response();
        } catch (NoVenueListingFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue listing not found',
            ], 404);
        }
    }

    /**
     * @param string $inquiryUuid
     * @return JsonResponse
     */
    public function showInquiry(string $inquiryUuid): JsonResponse
    {
        try {
            $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);

            return (new VenueInquiryResource($inquiry))->response();
        } catch (NoVenueInquiryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venue inquiry not found',
            ], 404);
        }
    }

    /**
     * @param UpdateVenueInquiryRequest $request
     * @param string $inquiryUuid
     * @return JsonResponse
     */
    public function updateInquiry(UpdateVenueInquiryRequest $request, string $inquiryUuid): JsonResponse
    {
        try {
            $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);
            $payload = $request->validated();

            if (! empty($payload['cancel'])) {
                $inquiry = $this->workflowService->cancel($inquiry);

                return (new VenueInquiryResource($inquiry))->response();
            }

            $visitScheduleChanging = isset($payload['visit_scheduled_date'])
                || isset($payload['visit_scheduled_time']);

            if (! $visitScheduleChanging) {
                return (new VenueInquiryResource($inquiry))->response();
            }

            $newDate = $payload['visit_scheduled_date'] ?? $inquiry->visit_scheduled_date?->format('Y-m-d');
            $newTime = $payload['visit_scheduled_time'] ?? (
                $inquiry->visit_scheduled_time
                    ? substr((string) $inquiry->visit_scheduled_time, 0, 5)
                    : null
            );

            $oldDate = $inquiry->visit_scheduled_date?->format('Y-m-d');
            $oldTime = $inquiry->visit_scheduled_time
                ? substr((string) $inquiry->visit_scheduled_time, 0, 5)
                : null;

            $isReschedule = ! empty($oldDate)
                && ! empty($newDate)
                && ($newDate !== $oldDate || $newTime !== $oldTime);

            $inquiry = $this->workflowService->scheduleVisit($inquiry, [
                'visit_scheduled_date' => $newDate,
                'visit_scheduled_time' => $newTime,
            ], $isReschedule);

            return (new VenueInquiryResource($inquiry))->response();
        } catch (NoVenueInquiryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Inquiry not found',
            ], 404);
        }
    }

    public function sendProposal(SendVenueProposalRequest $request, string $inquiryUuid): JsonResponse
    {
        try {
            $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);
            $validated = $request->validated();

            $defaultDisk = config('filesystems.default');
            $attachmentDisk = $defaultDisk === 'local' ? 'public' : $defaultDisk;
            $upload = $this->uploadRepository->create([
                'file' => $request->file('file'),
                'disk' => $attachmentDisk,
            ]);

            $inquiry = $this->workflowService->sendProposal($inquiry, [
                'proposal_amount' => $validated['proposal_amount'],
                'proposal_valid_until' => $validated['proposal_valid_until'],
                'proposal_upload_uuid' => $upload->uuid,
                'attachment_name' => $request->file('file')->getClientOriginalName(),
            ]);

            return (new VenueInquiryResource($inquiry))->response();
        } catch (NoVenueInquiryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Inquiry not found',
            ], 404);
        }
    }

    public function requestDeposit(RequestVenueDepositRequest $request, string $inquiryUuid): JsonResponse
    {
        try {
            $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);
            $validated = $request->validated();

            $inquiry = $this->workflowService->requestDeposit(
                $inquiry,
                (float) $validated['deposit_amount'],
                $validated['deposit_due_date'],
            );

            return (new VenueInquiryResource($inquiry))->response();
        } catch (NoVenueInquiryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Inquiry not found',
            ], 404);
        }
    }

    public function sendFinalBilling(SendVenueFinalBillingRequest $request, string $inquiryUuid): JsonResponse
    {
        try {
            $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);
            $validated = $request->validated();

            $inquiry = $this->workflowService->sendFinalBilling(
                $inquiry,
                (float) $validated['balance_amount'],
                $validated['balance_due_date'],
                (float) ($validated['additional_charges'] ?? 0),
            );

            return (new VenueInquiryResource($inquiry))->response();
        } catch (NoVenueInquiryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Inquiry not found',
            ], 404);
        }
    }

    public function completeInquiry(string $inquiryUuid): JsonResponse
    {
        try {
            $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);
            $inquiry = $this->workflowService->markCompleted($inquiry);

            return (new VenueInquiryResource($inquiry))->response();
        } catch (NoVenueInquiryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Inquiry not found',
            ], 404);
        }
    }

    private function sendInitialInquiryChatMessage(VenueInquiry $inquiry, string $body): void
    {
        try {
            $thread = $this->chatRepository->firstOrCreateThreadForInquiry($inquiry);

            $this->chatService->sendMessage(
                $thread,
                ChatThread::SENDER_CUSTOMER,
                $inquiry->user_uuid,
                $inquiry->full_name ?: $inquiry->email,
                $body,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send initial inquiry chat message', [
                'inquiry_uuid' => $inquiry->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param string $venueType
     * @return string
     */
    private function resolveCategory(string $venueType): string
    {
        $normalized = Str::lower($venueType);

        return match (true) {
            str_contains($normalized, 'conference') => VenueListing::CATEGORIES['CONFERENCE'],
            str_contains($normalized, 'ballroom') => VenueListing::CATEGORIES['BALLROOMS'],
            str_contains($normalized, 'outdoor'), str_contains($normalized, 'garden'), str_contains($normalized, 'rooftop') => VenueListing::CATEGORIES['OUTDOOR'],
            str_contains($normalized, 'loft'), str_contains($normalized, 'studio') => VenueListing::CATEGORIES['LOFT'],
            default => VenueListing::CATEGORIES['FUNCTION_HALLS'],
        };
    }

    /**
     * @param float|int|string $pricePerEvent
     * @return array<string, mixed>
     */
    private function defaultVenuePackages(float|int|string $pricePerEvent): array
    {
        return [
            'packages' => VenueListingPackageHelper::basePackages($pricePerEvent),
            'default_package_id' => 'full-day',
        ];
    }
}
