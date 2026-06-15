<?php

namespace App\Http\Controllers\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Repositories\AdminUserRepository;
use App\Http\Requests\Organizer\OrganizerCreateAdminUserRequest;
use App\Http\Requests\Organizer\OrganizerListAdminUserRequest;
use App\Http\Requests\Organizer\OrganizerUpdateAdminUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Services\Organizer\OrganizerContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrganizerAdminUserController extends Controller
{
    public function __construct(
        protected AdminUserRepository $adminUserRepository,
        protected OrganizerContextService $organizerContext,
    ) {
    }

    public function index(OrganizerListAdminUserRequest $request): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $perPage = $request->get('per_page', 15);

        $filters = array_merge($request->validated(), [
            'organization_uuid' => $organizationUuid,
        ]);

        $list = $this->adminUserRepository->getAll($filters);

        return AdminUserResource::collection($list->paginate($perPage))->response();
    }

    public function store(OrganizerCreateAdminUserRequest $request): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $payload = $request->validated();

        $this->organizerContext->assertAssignableRole($payload['role_uuid']);

        $payload['organization_uuid'] = $organizationUuid;
        $payload['email_verified_at'] = now();

        $adminUser = $this->adminUserRepository->create($payload);

        return (new AdminUserResource($adminUser->load('role')))->response()->setStatusCode(201);
    }

    public function show(string $uuid): JsonResponse
    {
        $adminUser = $this->organizerContext->assertAdminUserInOrganization($uuid);
        $adminUser->load(['role', 'organization', 'creator', 'updater']);

        return (new AdminUserResource($adminUser))->response();
    }

    public function update(OrganizerUpdateAdminUserRequest $request, string $uuid): JsonResponse
    {
        $adminUser = $this->organizerContext->assertAdminUserInOrganization($uuid);
        $payload = $request->validated();

        if (!empty($payload['role_uuid'])) {
            $this->organizerContext->assertAssignableRole($payload['role_uuid']);
        }

        unset($payload['organization_uuid']);

        $this->adminUserRepository->update($adminUser, $payload);

        return (new AdminUserResource($adminUser->fresh(['role', 'organization', 'creator', 'updater'])))->response();
    }

    public function destroy(string $uuid): Response|JsonResponse
    {
        $adminUser = $this->organizerContext->assertAdminUserInOrganization($uuid);
        $this->adminUserRepository->delete($adminUser);

        return $this->noContent();
    }

    public function availableRoles(): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();

        $roles = \App\Models\Role::query()
            ->visibleToOrganizer($organizationUuid)
            ->orderBy('name')
            ->get(['uuid', 'name', 'code', 'organization_uuid']);

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }
}
