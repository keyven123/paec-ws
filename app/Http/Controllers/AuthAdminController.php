<?php

namespace App\Http\Controllers;

use App\Http\Repositories\AdminUserRepository;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminUser;
use App\Constants\GeneralConstants;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use App\Http\Repositories\PasswordResetRepository;

class AuthAdminController extends Controller
{
    public function __construct(
        protected AdminUserRepository $adminUserRepository,
        protected PasswordResetRepository $passwordResetRepository
    )
    {
    }

    /**
     * Admin login
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $payload = $request->validated();

        // Find admin user by email
        $adminUser = $this->adminUserRepository->findByEmail($payload['email']);

        if (isset($payload['is_admin']) && $payload['is_admin'] == true && $adminUser && !$adminUser->role->is_admin) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials'],
            ]);
        }

        if ($adminUser && (bool) $adminUser->is_migrated) {
            $this->passwordResetRepository->initiateAdmin($payload);

            return response()->json([
                'data' => [
                    'message' => "For your account's security, we've recently updated our authentication system. Please check your email for a link to reset your password before you can log in again."
                ]
            ], 201);
        }

        if (!$adminUser || is_null($adminUser->email_verified_at)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials or email not verified.'],
            ]);
        }

        // Check if user is actually an admin (not customer)
        if (!$adminUser->isAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Access denied. Admin privileges required.'],
            ]);
        }

        // Verify password
        if (!Hash::check($payload['password'], $adminUser->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // Generate JWT token for admin user
        $token = auth('admin')->login($adminUser);

        // Update last login timestamp
        $this->adminUserRepository->updateLastLogin($adminUser);

        return $this->respondWithToken($token, $adminUser);
    }

    /**
     * Get authenticated admin user information
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        $adminUser = auth('admin')->user();

        if (!$adminUser instanceof AdminUser) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $adminUser->load(['role.permissions']);

        return response()->json([
            'success' => true,
            'data' => [
                'admin_user' => new AdminUserResource($adminUser),
                'role' => $adminUser->role,
                'permissions' => $adminUser->role ? $adminUser->role->permissions->pluck('code') : [],
                'role_permissions' => $adminUser->role ?
                    \App\Models\RolePermission::where('role_uuid', $adminUser->role->uuid)
                        ->pluck('access')->toArray() : []
            ]
        ]);
    }

    /**
     * Refresh admin token
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = auth('admin')->refresh();
            $adminUser = auth('admin')->user();

            if (!$adminUser instanceof AdminUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token'
                ], 401);
            }

            return $this->respondWithToken($newToken, $adminUser);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed'
            ], 401);
        }
    }

    /**
     * Admin logout
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        auth('admin')->logout();

        return response()->json([
            'success' => true,
            'message' => 'Admin logged out successfully'
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $adminUser = $this->adminUserRepository->findByEmail($payload['email']);

        if (!$adminUser instanceof AdminUser) {
            return response()->json([
                'success' => false,
                'message' => 'Admin user not found'
            ], 404);
        }

        $this->passwordResetRepository->initiateAdmin([
            'email' => $payload['email'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset link sent to email'
        ]);
    }

    /**
     * Change admin password
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $adminUser = auth('admin')->user();

        if (!$adminUser instanceof AdminUser) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $adminUser->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 400);
        }

        // Update password
        $adminUser->update([
            'password' => $request->new_password,
            'is_first_time_login' => false
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Update admin profile
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
        ]);

        $adminUser = auth('admin')->user();

        if (!$adminUser instanceof AdminUser) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $adminUser->update($request->only([
            'first_name', 'middle_name', 'last_name', 'phone_number'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => new AdminUserResource($adminUser->fresh())
        ]);
    }

    /**
     * Get admin dashboard statistics
     * @return JsonResponse
     */
    public function dashboardStats(): JsonResponse
    {
        // This can be expanded based on your specific dashboard needs
        $stats = [
            'total_admin_users' => AdminUser::adminsOnly()->count(),
            'active_admin_users' => AdminUser::adminsOnly()
                ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
                ->count(),
            'super_admins' => AdminUser::whereHas('role', function ($query) {
                $query->where('code', GeneralConstants::ROLES['SUPER_ADMIN']['name']);
            })->count(),
            'admins' => AdminUser::whereHas('role', function ($query) {
                $query->where('code', GeneralConstants::ROLES['ADMIN']['name']);
            })->count(),
            'organizers' => AdminUser::whereHas('role', function ($query) {
                $query->where('code', GeneralConstants::ROLES['ORGANIZER']['name']);
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Respond with token
     * @param string $token
     * @param AdminUser|null $adminUser
     * @return JsonResponse
     */
    protected function respondWithToken(string $token, AdminUser $adminUser): JsonResponse
    {
        if ($adminUser) {
            $adminUser->load(['role.permissions', 'organization']);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => Carbon::now()->addMinutes(auth('admin')->factory()->getTTL())->toDateTimeString(),
            'admin_user' => $adminUser ? new AdminUserResource($adminUser) : null,
            'role' => $adminUser->role->name,
            'is_admin' => !is_null($adminUser->organization_uuid) ? false : true,
            'permissions' => $adminUser && $adminUser->role ?
                \App\Models\RolePermission::where('role_uuid', $adminUser->role->uuid)
                    ->pluck('access')->toArray() : []
        ]);
    }
}
