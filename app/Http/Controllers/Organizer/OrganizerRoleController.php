<?php

namespace App\Http\Controllers\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\OrganizerCreateRoleRequest;
use App\Http\Requests\Organizer\OrganizerUpdateRoleRequest;
use App\Http\Requests\Role\AssignPermissionRequest;
use App\Http\Requests\Role\ListRoleRequest;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\Organizer\OrganizerContextService;
use App\Services\RolePermissionSyncService;
use Illuminate\Http\JsonResponse;

class OrganizerRoleController extends Controller
{
    public function __construct(
        private readonly RolePermissionSyncService $rolePermissionSync,
        private readonly OrganizerContextService $organizerContext,
    ) {
    }

    public function index(ListRoleRequest $request): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();

        $roles = Role::with(['permissions'])
            ->visibleToOrganizer($organizationUuid)
            ->filters($request->validated())
            ->orderBy('name')
            ->get();

        $roles = $this->rolePermissionSync->appendGrantsToRoles($roles->all());

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    public function store(OrganizerCreateRoleRequest $request): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $data = $request->validated();

        $role = Role::create([
            'organization_uuid' => $organizationUuid,
            'name' => $data['name'],
            'code' => $data['code'],
            'is_admin' => false,
            'created_by' => $this->actorUuid(),
            'updated_by' => $this->actorUuid(),
        ]);

        $this->syncRolePermissions($role, $data);

        $role->load('permissions');
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => $role,
        ], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $role = Role::with(['permissions'])->findOrFail($uuid);
        $this->organizerContext->assertRoleVisibleToOrganizer($role);
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'data' => $role,
        ]);
    }

    public function update(OrganizerUpdateRoleRequest $request, string $uuid): JsonResponse
    {
        $role = Role::findOrFail($uuid);
        $this->organizerContext->assertRoleOwnedByOrganization($role);

        $data = $request->validated();

        $role->update([
            'name' => $data['name'],
            'code' => $data['code'],
            'updated_by' => $this->actorUuid(),
        ]);

        $this->syncRolePermissions($role, $data);

        $role->load('permissions');
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => $role,
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $role = Role::findOrFail($uuid);
        $this->organizerContext->assertRoleOwnedByOrganization($role);

        if ($role->users()->count() > 0 || $role->adminUsers()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role that is assigned to users',
            ], 400);
        }

        RolePermission::where('role_uuid', $role->uuid)->delete();
        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
        ]);
    }

    public function assignPermissions(AssignPermissionRequest $request, string $uuid): JsonResponse
    {
        $role = Role::findOrFail($uuid);
        $this->organizerContext->assertRoleOwnedByOrganization($role);

        $data = $request->validated();
        $this->syncRolePermissions($role, $data);

        $role->load('permissions');
        $role->setAttribute('permission_grants', $this->rolePermissionSync->grantsFromRole($role));

        return response()->json([
            'success' => true,
            'message' => 'Permissions assigned successfully',
            'data' => $role,
        ]);
    }

    private function actorUuid(): ?string
    {
        return auth('admin')->user()?->uuid;
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
                'access' => true,
            ]);
        }
    }
}
