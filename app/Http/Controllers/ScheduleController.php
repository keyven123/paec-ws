<?php

namespace App\Http\Controllers;

use App\Http\Repositories\ScheduleRepository;
use App\Http\Requests\Schedule\CreateScheduleRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Requests\Schedule\ListScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Exceptions\NoScheduleFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class ScheduleController extends Controller
{
    public function __construct(protected ScheduleRepository $scheduleRepository)
    {
    }

    /**
     * Display a listing of schedules.
     * @param ListScheduleRequest $request
     * @return JsonResponse
     */
    public function index(ListScheduleRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->scheduleRepository->getAll($request->validated());
        return ScheduleResource::collection($list->paginate($perPage))->response();
    }

    public function getEventSchedulePublic(string $uuid): JsonResponse
    {
        $schedule = $this->scheduleRepository->getEventScheduleByEventUuid($uuid);
        return ScheduleResource::collection($schedule->get())->response();
    }

    /**
     * Store a newly created schedule.
     * @param CreateScheduleRequest $request
     * @return JsonResponse
     */
    public function store(CreateScheduleRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $schedule = $this->scheduleRepository->create($payload);
        return (new ScheduleResource($schedule))->response()->setStatusCode(201);
    }

    /**
     * Display the specified schedule.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $schedule = $this->scheduleRepository->fetchOrThrow('uuid', $uuid);
            return (new ScheduleResource($schedule))->response();
        } catch (NoScheduleFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule not found'
            ], 404);
        }
    }

    /**
     * Update the specified schedule.
     * @param UpdateScheduleRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateScheduleRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $schedule = $this->scheduleRepository->fetchOrThrow('uuid', $uuid);
            $this->scheduleRepository->update($schedule, $payload);
            return (new ScheduleResource($schedule->fresh()))->response();
        } catch (NoScheduleFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule not found'
            ], 404);
        }
    }

    /**
     * Remove the specified schedule from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $schedule = $this->scheduleRepository->fetchOrThrow('uuid', $uuid);
            $this->scheduleRepository->delete($schedule);
            return $this->noContent();
        } catch (NoScheduleFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}
