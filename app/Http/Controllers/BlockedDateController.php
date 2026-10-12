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
use Illuminate\Support\Facades\DB;
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

        $startDate = Carbon::parse($payload['blocked_date'])->startOfDay();
        $endDate = isset($payload['blocked_date_end'])
            ? Carbon::parse($payload['blocked_date_end'])->startOfDay()
            : $startDate->copy();
        $reason = $payload['reason'] ?? null;

        if ($startDate->equalTo($endDate)) {
            return $this->storeSingleDate($blockable, $startDate->toDateString(), $reason);
        }

        if ($startDate->diffInDays($endDate) + 1 > StoreBlockedDateRequest::MAX_RANGE_DAYS) {
            return response()->json([
                'success' => false,
                'message' => 'You can block up to ' . StoreBlockedDateRequest::MAX_RANGE_DAYS . ' days at a time.',
            ], 422);
        }

        return $this->storeDateRange($blockable, $startDate, $endDate, $reason);
    }

    private function storeSingleDate(Model $blockable, string $date, ?string $reason): JsonResponse
    {
        $result = $this->blockDate($blockable, $date, $reason);

        return match ($result['status']) {
            'conflict' => response()->json([
                'success' => false,
                'message' => 'There are existing tickets scheduled for this date.',
            ], 422),
            'exists' => response()->json([
                'success' => false,
                'message' => 'This date is already blocked.',
            ], 422),
            default => (new BlockedDateResource($result['record']))->response()->setStatusCode(201),
        };
    }

    private function storeDateRange(Model $blockable, Carbon $startDate, Carbon $endDate, ?string $reason): JsonResponse
    {
        $blocked = [];
        $skipped = [];

        DB::transaction(function () use ($blockable, $startDate, $endDate, $reason, &$blocked, &$skipped) {
            for ($day = $startDate->copy(); $day->lte($endDate); $day->addDay()) {
                $date = $day->toDateString();
                $result = $this->blockDate($blockable, $date, $reason);

                if ($result['record'] !== null) {
                    $blocked[] = $result['record'];
                    continue;
                }

                $skipped[] = [
                    'date' => $date,
                    'reason' => $result['status'] === 'conflict'
                        ? 'Has existing tickets'
                        : 'Already blocked',
                ];
            }
        });

        if ($blocked === []) {
            return response()->json([
                'success' => false,
                'message' => 'No dates were blocked. Every date in this range is already blocked or has existing tickets.',
                'skipped' => $skipped,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => BlockedDateResource::collection(collect($blocked))->resolve(),
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * Block one date for the given resource.
     *
     * @return array{status: 'created'|'restored'|'exists'|'conflict', record: Model|null}
     */
    private function blockDate(Model $blockable, string $date, ?string $reason): array
    {
        $blockableType = $blockable->getMorphClass();
        $blockableUuid = $blockable->getKey();

        if ($blockable instanceof Blockable && $blockable->hasBlockedDateConflict($date)) {
            return ['status' => 'conflict', 'record' => null];
        }

        // Restore a previously soft-deleted record for the same date if present.
        $softDeletedRecord = $this->blockedDateRepository->findSoftDeleted(
            $blockableType,
            $blockableUuid,
            $date
        );

        if ($softDeletedRecord) {
            $this->blockedDateRepository->restore($softDeletedRecord);
            if ($reason !== null) {
                $this->blockedDateRepository->update($softDeletedRecord, ['reason' => $reason]);
            }

            return ['status' => 'restored', 'record' => $softDeletedRecord->fresh()];
        }

        $existingRecord = $this->blockedDateRepository->getAll([
            'blockable_type' => $blockableType,
            'blockable_uuid' => $blockableUuid,
        ])->whereDate('blocked_date', $date)->first();

        if ($existingRecord) {
            return ['status' => 'exists', 'record' => null];
        }

        try {
            $record = $this->blockedDateRepository->create([
                'blockable_type' => $blockableType,
                'blockable_uuid' => $blockableUuid,
                'blocked_date' => $date,
                'reason' => $reason,
            ]);

            return ['status' => 'created', 'record' => $record];
        } catch (QueryException $e) {
            $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
                || str_contains($e->getMessage(), 'UNIQUE constraint failed');

            if ($isDuplicate) {
                return ['status' => 'exists', 'record' => null];
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
