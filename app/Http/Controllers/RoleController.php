<?php

namespace App\Http\Controllers;

use App\Http\Requests\Role\AssignPermissionRequest;
use App\Http\Requests\Role\CreateRoleRequest;
use App\Http\Requests\Role\ListRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\RolePermissionSyncService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(
        private readonly RolePermissionSyncService $rolePermissionSync
    ) {
    }

    /**
     * Display a listing of roles
     */
    public function index(ListRoleRequest $request): JsonResponse
    {
        $roles = Role::with(['permissions'])->filters($request->validated())->get();
        $roles = $this->rolePermissionSync->appendGrantsToRoles($roles->all());

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Store a newly created role
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = Role::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'is_admin' => $data['is_admin'] ?? false,
            'created_by' => $this->actorUuid(),
            'updated_by' => $this->actorUuid(),
        ]);

        $this->syncRolePermissions($role, $data);

        $role->load('permissions');
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => $role
        ], 201);
    }

    /**
     * Display the specified role
     */
    public function show(string $uuid): JsonResponse
    {
        $role = Role::with(['permissions'])->findOrFail($uuid);
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'data' => $role
        ]);
    }

    /**
     * Update the specified role
     */
    public function update(UpdateRoleRequest $request, string $uuid): JsonResponse
    {
        $role = Role::findOrFail($uuid);

        $data = $request->validated();

        $updatePayload = [
            'name' => $data['name'],
            'code' => $data['code'],
            'updated_by' => $this->actorUuid(),
        ];

        if (array_key_exists('is_admin', $data)) {
            $updatePayload['is_admin'] = $data['is_admin'];
        }

        $role->update($updatePayload);

        $this->syncRolePermissions($role, $data);

        $role->load('permissions');
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => $role
        ]);
    }

    /**
     * Remove the specified role
     */
    public function destroy(string $uuid): JsonResponse
    {
        $role = Role::findOrFail($uuid);

        if ($role->users()->count() > 0 || $role->adminUsers()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role that is assigned to users'
            ], 400);
        }

        RolePermission::where('role_uuid', $role->uuid)->delete();

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully'
        ]);
    }

    /**
     * Assign permissions to role
     */
    public function assignPermissions(AssignPermissionRequest $request, string $uuid): JsonResponse
    {
        $role = Role::findOrFail($uuid);

        $data = $request->validated();

        $this->syncRolePermissions($role, $data);

        $role->load('permissions');
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'message' => 'Permissions assigned successfully',
            'data' => $role
        ]);
    }

    private function actorUuid(): ?string
    {
        $user = auth('admin')->user();

        return $user?->uuid;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRolePermissions(Role $role, array $data): void
    {
        if (isset($data['permission_grants'])) {
            $this->rolePermissionSync->syncGrants($role, $data['permission_grants']);

            return;
        }

        if (!isset($data['permissions'])) {
            return;
        }

        RolePermission::where('role_uuid', $role->uuid)->delete();

        foreach ($data['permissions'] as $permissionUuid) {
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permissionUuid,
                'access' => true
            ]);
        }
    }
}
