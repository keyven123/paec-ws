<?php

namespace App\Http\Controllers;

use App\Http\Repositories\ScheduleTimeRepository;
use App\Http\Requests\ScheduleTime\CreateScheduleTimeRequest;
use App\Http\Requests\ScheduleTime\UpdateScheduleTimeRequest;
use App\Http\Requests\ScheduleTime\ListScheduleTimeRequest;
use App\Http\Resources\ScheduleTimeResource;
use App\Exceptions\NoScheduleTimeFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class ScheduleTimeController extends Controller
{
    public function __construct(protected ScheduleTimeRepository $scheduleTimeRepository)
    {
    }

    /**
     * Display a listing of schedule times.
     * @param ListScheduleTimeRequest $request
     * @return JsonResponse
     */
    public function index(ListScheduleTimeRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->scheduleTimeRepository->getAll($request->validated());
        return ScheduleTimeResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created schedule time.
     * @param CreateScheduleTimeRequest $request
     * @return JsonResponse
     */
    public function store(CreateScheduleTimeRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $scheduleTime = $this->scheduleTimeRepository->create($payload);
        return (new ScheduleTimeResource($scheduleTime))->response()->setStatusCode(201);
    }

    /**
     * Display the specified schedule time.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $scheduleTime = $this->scheduleTimeRepository->fetchOrThrow('uuid', $uuid);
            return (new ScheduleTimeResource($scheduleTime))->response();
        } catch (NoScheduleTimeFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule time not found'
            ], 404);
        }
    }

    /**
     * Update the specified schedule time.
     * @param UpdateScheduleTimeRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateScheduleTimeRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $scheduleTime = $this->scheduleTimeRepository->fetchOrThrow('uuid', $uuid);
            $this->scheduleTimeRepository->update($scheduleTime, $payload);
            return (new ScheduleTimeResource($scheduleTime->fresh()))->response();
        } catch (NoScheduleTimeFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule time not found'
            ], 404);
        }
    }

    /**
     * Remove the specified schedule time from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $scheduleTime = $this->scheduleTimeRepository->fetchOrThrow('uuid', $uuid);
            $this->scheduleTimeRepository->delete($scheduleTime);
            return $this->noContent();
        } catch (NoScheduleTimeFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule time not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}
