<?php

namespace App\Http\Controllers;

use App\Contracts\Blockable;
use App\Exceptions\NoBlockedDateFoundException;
use App\Http\Repositories\BlockedDateRepository;
use App\Http\Requests\BlockedDate\ListBlockedDateRequest;
use App\Http\Requests\BlockedDate\StoreBlockedDateRequest;
use App\Http\Resources\BlockedDateResource;
use App\Models\Event;
use App\Models\VenueListing;
use App\Services\VenueListingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Generic, polymorphic controller for managing blocked dates on any
 * "blockable" resource (events, venue listings, ...).
 *
 * Routes wire the concrete resource by setting a `blockableType` route default
 * to one of the keys registered in self::BLOCKABLE_TYPES
 * (e.g. ->defaults('blockableType', 'event')).
 */
class BlockedDateController extends Controller
{
    /**
     * Resources that support blocked dates, keyed by their route alias.
     *
     * Add an entry here (and apply the App\Contracts\Blockable contract to the
     * model) to expose blocked dates on a new module.
     */
    private const BLOCKABLE_TYPES = [
        'event' => Event::class,
        'venue_listing' => VenueListing::class,
    ];

    public function __construct(
        protected BlockedDateRepository $blockedDateRepository,
        protected VenueListingAvailabilityService $venueListingAvailabilityService,
    ) {
    }

    public function index(ListBlockedDateRequest $request, string $uuid): JsonResponse
    {
        $blockable = $this->resolveBlockable($request, $uuid);

        $filters = $request->validated();
        $filters['blockable_type'] = $blockable->getMorphClass();
        $filters['blockable_uuid'] = $blockable->getKey();

        $list = $this->blockedDateRepository->getAll($filters)->get();

        return BlockedDateResource::collection($list)->response();
    }

    public function store(StoreBlockedDateRequest $request, string $uuid): JsonResponse
    {
        $blockable = $this->resolveBlockable($request, $uuid);
        $payload = $request->validated();

        $blockedDate = Carbon::parse($payload['blocked_date'])->toDateString();
        $blockableType = $blockable->getMorphClass();
        $blockableUuid = $blockable->getKey();

        if ($blockable instanceof Blockable && $blockable->hasBlockedDateConflict($blockedDate)) {
            return response()->json([
                'success' => false,
                'message' => 'There are existing tickets scheduled for this date.',
            ], 422);
        }

        // Restore a previously soft-deleted record for the same date if present.
        $softDeletedRecord = $this->blockedDateRepository->findSoftDeleted(
            $blockableType,
            $blockableUuid,
            $blockedDate
        );

        if ($softDeletedRecord) {
            $this->blockedDateRepository->restore($softDeletedRecord);
            if (isset($payload['reason'])) {
                $this->blockedDateRepository->update($softDeletedRecord, [
                    'reason' => $payload['reason'],
                ]);
            }
            return (new BlockedDateResource($softDeletedRecord->fresh()))->response()->setStatusCode(201);
        }

        $existingRecord = $this->blockedDateRepository->getAll([
            'blockable_type' => $blockableType,
            'blockable_uuid' => $blockableUuid,
        ])->whereDate('blocked_date', $blockedDate)->first();

        if ($existingRecord) {
            return response()->json([
                'success' => false,
                'message' => 'This date is already blocked.',
            ], 422);
        }

        try {
            $record = $this->blockedDateRepository->create([
                'blockable_type' => $blockableType,
                'blockable_uuid' => $blockableUuid,
                'blocked_date' => $payload['blocked_date'],
                'reason' => $payload['reason'] ?? null,
            ]);
            return (new BlockedDateResource($record))->response()->setStatusCode(201);
        } catch (QueryException $e) {
            $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
                || str_contains($e->getMessage(), 'UNIQUE constraint failed');

            if ($isDuplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'This date is already blocked.',
                ], 422);
            }
            throw $e;
        }
    }

    public function destroy(Request $request, string $uuid, string $blocked_date_uuid): Response|JsonResponse
    {
        $blockable = $this->resolveBlockable($request, $uuid);

        try {
            $record = $this->blockedDateRepository->getAll([
                'blockable_type' => $blockable->getMorphClass(),
                'blockable_uuid' => $blockable->getKey(),
            ])->where('uuid', $blocked_date_uuid)->first();

            if (!$record) {
                throw new NoBlockedDateFoundException();
            }
            $this->blockedDateRepository->delete($record);
            return $this->noContent();
        } catch (NoBlockedDateFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Blocked date not found',
            ], 404);
        }
    }

    /**
     * Public read-only endpoint: list dates that are unavailable for new
     * bookings on a venue listing resolved by its public slug. No auth required.
     *
     * This merges two sources so the customer date pickers can disable them:
     *   1. Manually blocked dates (merchant "Closed" days).
     *   2. Dates with a CONFIRMED booking — these are not stored as blocked
     *      dates (so the merchant calendar shows them as bookings, not closures)
     *      but are still unselectable for new customers.
     */
    public function publicIndexBySlug(Request $request, string $slug): JsonResponse
    {
        $venue = VenueListing::query()->where('slug', $slug)->whereNull('deleted_at')->first();

        if ($venue === null) {
            return response()->json([
                'success' => false,
                'message' => 'Venue listing not found',
            ], 404);
        }

        $data = $this->venueListingAvailabilityService->publicUnavailableDates($venue);

        return response()->json(['data' => $data]);
    }

    /**
     * Resolve the blockable model instance from the route's `blockableType`
     * default (a key in self::BLOCKABLE_TYPES) and the {uuid} route parameter.
     */
    protected function resolveBlockable(Request $request, string $uuid): Model
    {
        $type = (string) $request->route('blockableType');
        $modelClass = self::BLOCKABLE_TYPES[$type] ?? null;

        if ($modelClass === null || !is_subclass_of($modelClass, Model::class)) {
            abort(404);
        }

        $blockable = $modelClass::query()->where('uuid', $uuid)->first();

        if ($blockable === null) {
            abort(response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404));
        }

        return $blockable;
    }
}
