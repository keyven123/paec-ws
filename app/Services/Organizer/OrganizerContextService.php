<?php

namespace App\Services\Organizer;

use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class OrganizerContextService
{
    public function organizationUuidOrAbort(): string
    {
        $user = auth('admin')->user();

        if (!$user instanceof AdminUser || empty($user->organization_uuid)) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Organization context is required.',
            ], Response::HTTP_FORBIDDEN));
        }

        return $user->organization_uuid;
    }

    public function assertRoleVisibleToOrganizer(Role $role): void
    {
        $organizationUuid = $this->organizationUuidOrAbort();

        $visible = Role::query()
            ->visibleToOrganizer($organizationUuid)
            ->where('uuid', $role->uuid)
            ->exists();

        if (!$visible) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Role not found.',
            ], Response::HTTP_NOT_FOUND));
        }
    }

    public function assertRoleOwnedByOrganization(Role $role): void
    {
        $organizationUuid = $this->organizationUuidOrAbort();

        if ($role->organization_uuid !== $organizationUuid) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'You can only modify roles owned by your organization.',
            ], Response::HTTP_FORBIDDEN));
        }
    }

    public function assertAdminUserInOrganization(string $adminUserUuid): AdminUser
    {
        $organizationUuid = $this->organizationUuidOrAbort();

        $adminUser = AdminUser::query()
            ->where('uuid', $adminUserUuid)
            ->where('organization_uuid', $organizationUuid)
            ->first();

        if (!$adminUser) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Admin user not found.',
            ], Response::HTTP_NOT_FOUND));
        }

        return $adminUser;
    }

    public function assertAssignableRole(string $roleUuid): Role
    {
        $organizationUuid = $this->organizationUuidOrAbort();

        $role = Role::query()
            ->visibleToOrganizer($organizationUuid)
            ->where('uuid', $roleUuid)
            ->first();

        if (!$role) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'The selected role is not available for your organization.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $role;
    }
}
