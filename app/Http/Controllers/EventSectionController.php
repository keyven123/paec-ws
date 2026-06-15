<?php

namespace App\Http\Controllers;

use App\Http\Repositories\EventSectionRepository;
use App\Http\Requests\EventSection\CreateEventSectionRequest;
use App\Http\Requests\EventSection\UpdateEventSectionRequest;
use App\Http\Requests\EventSection\ListEventSectionRequest;
use App\Http\Resources\EventSectionResource;
use App\Exceptions\NoEventSectionFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class EventSectionController extends Controller
{
    public function __construct(protected EventSectionRepository $eventSectionRepository)
    {
    }

    /**
     * Display a listing of event sections.
     * @param ListEventSectionRequest $request
     * @return JsonResponse
     */
    public function index(ListEventSectionRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->eventSectionRepository->getAll($request->validated());
        return EventSectionResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created event section.
     * @param CreateEventSectionRequest $request
     * @return JsonResponse
     */
    public function store(CreateEventSectionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $eventSection = $this->eventSectionRepository->create($payload);
        return (new EventSectionResource($eventSection))->response()->setStatusCode(201);
    }

    /**
     * Display the specified event section.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $eventSection = $this->eventSectionRepository->fetchOrThrow('uuid', $uuid);
            return (new EventSectionResource($eventSection))->response();
        } catch (NoEventSectionFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event section not found'
            ], 404);
        }
    }

    /**
     * Update the specified event section.
     * @param UpdateEventSectionRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateEventSectionRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $eventSection = $this->eventSectionRepository->fetchOrThrow('uuid', $uuid);
            $this->eventSectionRepository->update($eventSection, $payload);
            return (new EventSectionResource($eventSection->fresh()))->response();
        } catch (NoEventSectionFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event section not found'
            ], 404);
        }
    }

    /**
     * Remove the specified event section from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $eventSection = $this->eventSectionRepository->fetchOrThrow('uuid', $uuid);
            $this->eventSectionRepository->delete($eventSection);
            return $this->noContent();
        } catch (NoEventSectionFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event section not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}
