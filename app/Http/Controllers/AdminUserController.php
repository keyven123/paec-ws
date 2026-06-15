<?php

namespace App\Http\Controllers;

use App\Http\Repositories\AdminUserRepository;
use App\Http\Requests\AdminUser\CreateAdminUserRequest;
use App\Http\Requests\AdminUser\UpdateAdminUserRequest;
use App\Http\Requests\AdminUser\ListAdminUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Exceptions\NoAdminUserFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function __construct(protected AdminUserRepository $adminUserRepository)
    {
    }

    /**
     * Display a listing of admin users.
     * @param ListAdminUserRequest $request
     * @return JsonResponse
     */
    public function index(ListAdminUserRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->adminUserRepository->getAll($request->validated());
        return AdminUserResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created admin user.
     * @param CreateAdminUserRequest $request
     * @return JsonResponse
     */
    public function store(CreateAdminUserRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $adminUser = $this->adminUserRepository->create($payload);
        return (new AdminUserResource($adminUser->load('role')))->response()->setStatusCode(201);
    }

    /**
     * Display the specified admin user.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $adminUser = $this->adminUserRepository->fetchOrThrow('uuid', $uuid);
            $adminUser->load(['role', 'creator', 'updater']);
            return (new AdminUserResource($adminUser))->response();
        } catch (NoAdminUserFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin user not found'
            ], 404);
        }
    }

    /**
     * Update the specified admin user.
     * @param UpdateAdminUserRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateAdminUserRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $adminUser = $this->adminUserRepository->fetchOrThrow('uuid', $uuid);
            $this->adminUserRepository->update($adminUser, $payload);
            return (new AdminUserResource($adminUser->fresh(['role', 'creator', 'updater'])))->response();
        } catch (NoAdminUserFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin user not found'
            ], 404);
        }
    }

    /**
     * Remove the specified admin user from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $adminUser = $this->adminUserRepository->fetchOrThrow('uuid', $uuid);
            $this->adminUserRepository->delete($adminUser);
            return $this->noContent();
        } catch (NoAdminUserFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin user not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    /**
     * Get available admin roles for dropdown/selection
     * @return JsonResponse
     */
    public function availableRoles(): JsonResponse
    {
        $roles = $this->adminUserRepository->getAvailableRoles();

        return response()->json([
            'success' => true,
            'data' => $roles->map(function ($role) {
                return [
                    'uuid' => $role->uuid,
                    'name' => $role->name,
                    'code' => $role->code,
                ];
            })
        ]);
    }
}
