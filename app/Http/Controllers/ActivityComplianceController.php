<?php

namespace App\Http\Controllers;

use App\Exceptions\NoResourceFoundException;
use App\Http\Repositories\ActivityComplianceRepository;
use App\Http\Requests\ActivityCompliance\CreateActivityComplianceRequest;
use App\Http\Requests\ActivityCompliance\UpdateActivityComplianceRequest;
use App\Http\Resources\ActivityComplianceResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ActivityComplianceController extends Controller
{
    public function __construct(protected ActivityComplianceRepository $activityComplianceRepository)
    {
    }

    public function indexByOrganization(string $organizationUuid): JsonResponse
    {
        $events = $this->activityComplianceRepository
            ->eventsWithCompliancesForOrganization($organizationUuid);

        $data = $events->map(function (Event $event) {
            return [
                'uuid' => $event->uuid,
                'event_name' => $event->event_name,
                'status' => $event->status,
                'activity_compliances' => ActivityComplianceResource::collection($event->activityCompliances),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function store(CreateActivityComplianceRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $event = $this->activityComplianceRepository->fetchEventForOrganizationOrThrow(
                $payload['event_uuid'],
                $payload['organization_uuid'],
            );

            $created = $this->activityComplianceRepository->createForEvent($event, $payload);

            return (new ActivityComplianceResource($created))
                ->response()
                ->setStatusCode(201);
        } catch (NoResourceFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found for this organization',
            ], 404);
        }
    }

    public function update(UpdateActivityComplianceRequest $request, string $uuid): JsonResponse
    {
        try {
            $compliance = $this->activityComplianceRepository->fetchOrThrow($uuid);
            $updated = $this->activityComplianceRepository->update(
                $compliance,
                $request->validated(),
            );

            return (new ActivityComplianceResource($updated))->response();
        } catch (NoResourceFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Activity compliance not found',
            ], 404);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $compliance = $this->activityComplianceRepository->fetchOrThrow($uuid);
            $this->activityComplianceRepository->delete($compliance);

            return $this->noContent();
        } catch (NoResourceFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Activity compliance not found',
            ], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
