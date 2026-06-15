<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PortalController extends Controller
{
    /**
     * Get admin dashboard data
     */
    public function adminDashboard(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Welcome to Admin Portal',
            'data' => [
                'total_users' => \App\Models\User::count(),
                'total_roles' => \App\Models\Role::count(),
                'total_permissions' => \App\Models\Permission::count(),
                'user_permissions' => auth('api')->user()->role ? auth('api')->user()->role->permissions->pluck('code') : []
            ]
        ]);
    }

    /**
     * Get customer portal data
     */
    public function customerPortal(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Welcome to Customer Portal',
            'data' => [
                'available_events' => [], // This would be populated with actual events
                'user_permissions' => auth('api')->user()->role ? auth('api')->user()->role->permissions->pluck('code') : []
            ]
        ]);
    }

    /**
     * Check user permissions
     */
    public function checkPermissions(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'role' => $user->role,
                'permissions' => $user->role ? $user->role->permissions : [],
                'has_admin_access' => $user->hasPermission('manage_roles'),
                'has_customer_access' => $user->hasPermission('view_events'),
            ]
        ]);
    }
}
