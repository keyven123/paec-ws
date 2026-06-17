<?php

namespace App\Http\Controllers;

use App\Constants\PermissionRoleScope;
use App\Models\Permission;
use App\Services\Organizer\OrganizerPermissionCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(
        protected OrganizerPermissionCatalogService $merchantPartnerCatalog,
    ) {
    }
    /**
     * Display a listing of permissions.
     *
     * Query: role_scope=admin|organizer|shared — limits to permissions assignable for that role type.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Permission::query()->orderBy('module')->orderBy('name');

        $roleScope = $request->query('role_scope');

        if ($roleScope === PermissionRoleScope::ADMIN) {
            $query->forAdminRole();
        } elseif ($roleScope === PermissionRoleScope::ORGANIZER) {
            $query->forOrganizerRole();
        } elseif ($roleScope === PermissionRoleScope::SHARED) {
            $query->shared();
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * Merchant partner permission catalog from organizer_permissions.csv.
     */
    public function merchantPartnerCatalog(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->merchantPartnerCatalog->getCatalogRows(),
        ]);
    }

    /**
     * Permissions assignable to merchant partner roles (catalog-filtered).
     */
    public function merchantPartnerPermissions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->merchantPartnerCatalog->getAssignablePermissions(),
        ]);
    }

    /**
     * Display the specified permission
     */
    public function show(string $uuid): JsonResponse
    {
        $permission = Permission::findOrFail($uuid);

        return response()->json([
            'success' => true,
            'data' => $permission
        ]);
    }

    /**
     * @deprecated Use getByRoleScope. Legacy alias for access-type filtering.
     */
    public function getByAccess(string $access): JsonResponse
    {
        $permissions = Permission::query()
            ->whereJsonContains('available_access', $access)
            ->orWhere('role_scope', PermissionRoleScope::SHARED)
            ->orderBy('module')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Get permissions by role type (admin or organizer portal).
     */
    public function getByRoleScope(string $roleScope): JsonResponse
    {
        $query = Permission::query()->orderBy('module')->orderBy('name');

        if ($roleScope === PermissionRoleScope::ADMIN) {
            $query->forAdminRole();
        } elseif ($roleScope === PermissionRoleScope::ORGANIZER) {
            $query->forOrganizerRole();
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid role_scope. Use admin or organizer.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }
}
