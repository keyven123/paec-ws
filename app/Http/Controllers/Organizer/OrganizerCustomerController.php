<?php

namespace App\Http\Controllers\Organizer;

use App\Constants\GeneralConstants;
use App\Http\Controllers\Controller;
use App\Http\Repositories\UserRepository;
use App\Http\Requests\Organizer\OrganizerCreateCustomerRequest;
use App\Http\Requests\Organizer\OrganizerListCustomerRequest;
use App\Http\Requests\Organizer\OrganizerUpdateCustomerRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Services\Organizer\OrganizerContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrganizerCustomerController extends Controller
{
    public function __construct(
        protected UserRepository $userRepository,
        protected OrganizerContextService $organizerContext,
    ) {
    }

    public function index(OrganizerListCustomerRequest $request): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $perPage = $request->get('per_page', 15);

        $list = $this->userRepository->getAllForOrganization(
            $organizationUuid,
            $request->validated(),
        );

        return UserResource::collection($list->paginate($perPage))->response();
    }

    public function store(OrganizerCreateCustomerRequest $request): JsonResponse
    {
        $this->organizerContext->organizationUuidOrAbort();
        $payload = $request->validated();

        $customerRole = Role::query()
            ->where('code', GeneralConstants::ROLES['CUSTOMER']['name'])
            ->firstOrFail();

        $payload['role_uuid'] = $customerRole->uuid;

        $user = $this->userRepository->create($payload);

        return (new UserResource($user->load(['profileImage', 'role'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $uuid): JsonResponse
    {
        $user = $this->organizerContext->assertCustomerVisibleToOrganizer($uuid);
        $user->load(['profileImage', 'role']);

        return (new UserResource($user))->response();
    }

    public function update(OrganizerUpdateCustomerRequest $request, string $uuid): JsonResponse
    {
        $user = $this->organizerContext->assertCustomerVisibleToOrganizer($uuid);
        $payload = $request->validated();
        unset($payload['uuid'], $payload['role_uuid']);

        $this->userRepository->update($user, $payload);

        return (new UserResource($user->fresh(['profileImage', 'role'])))->response();
    }

    public function destroy(string $uuid): Response|JsonResponse
    {
        $user = $this->organizerContext->assertCustomerVisibleToOrganizer($uuid);
        $this->userRepository->delete($user);

        return $this->noContent();
    }

    public function stats(string $uuid): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $user = $this->organizerContext->assertCustomerVisibleToOrganizer($uuid);
        $stats = $this->userRepository->getUserStats($user, $organizationUuid);

        return response()->json([
            'success' => true,
            'message' => 'User statistics retrieved successfully',
            'data' => $stats,
        ]);
    }

    public function recentActivity(string $uuid): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $user = $this->organizerContext->assertCustomerVisibleToOrganizer($uuid);
        $activity = $this->userRepository->getUserRecentActivity($user, $organizationUuid);

        return response()->json([
            'success' => true,
            'message' => 'User recent activity retrieved successfully',
            'data' => $activity,
        ]);
    }

    public function tickets(string $uuid): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $user = $this->organizerContext->assertCustomerVisibleToOrganizer($uuid);
        $perPage = request()->get('per_page', 10);
        $status = request()->get('status');
        $q = request()->get('q');
        $search = is_string($q) ? trim($q) : null;

        $tickets = $this->userRepository->getUserTickets(
            $user,
            is_string($status) ? $status : null,
            $search !== '' ? $search : null,
            $organizationUuid,
        );

        return response()->json($tickets->paginate($perPage));
    }

    public function export(): Response
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $csvContent = $this->userRepository->exportUsers($organizationUuid);
        $fileName = 'list_of_customers_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control' => 'no-cache, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
