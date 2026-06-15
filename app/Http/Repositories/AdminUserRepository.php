<?php

namespace App\Http\Repositories;

use App\Exceptions\NoAdminUserFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\AdminUser;
use App\Models\Role;
use App\Constants\GeneralConstants;
use App\Helpers\GeneralHelper;
use Illuminate\Database\Eloquent\Builder;

class AdminUserRepository
{
    /**
     * @param AdminUser $adminUser
     */
    public function __construct(protected AdminUser $adminUser)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->adminUser->filters($filters)
            ->with(['role', 'organization'])
            ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch admin user or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return AdminUser
     * @throws NoAdminUserFoundException
     */
    public function fetchOrThrow(string $key, string $value): AdminUser
    {
        $adminUser = $this->adminUser->where($key, $value)->first();

        if (is_null($adminUser)) {
            throw new NoAdminUserFoundException();
        }

        return $adminUser;
    }

    /**
     * Fetch admin user with role or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return AdminUser
     * @throws NoAdminUserFoundException
     */
    public function fetchWithRoleOrThrow(string $key, string $value): AdminUser
    {
        $adminUser = $this->adminUser->with(['role'])->where($key, $value)->first();

        if (is_null($adminUser)) {
            throw new NoAdminUserFoundException();
        }

        return $adminUser;
    }

    /**
     * @param array $payload
     * @return AdminUser
     */
    public function create(array $payload): AdminUser
    {
        $payload['email_verified_at'] = now();
        $adminUserPayload = GeneralHelper::unsetUnknownAndNullFields($payload, AdminUser::DATA);
        return $this->adminUser->create($adminUserPayload);
    }

    /**
     * @param AdminUser $adminUser
     * @param array $payload
     * @return bool|AdminUser
     */
    public function update(AdminUser $adminUser, array $payload): bool|AdminUser
    {
        $adminUserPayload = GeneralHelper::unsetUnknownAndNullFields($payload, AdminUser::DATA);
        return $adminUser->update($adminUserPayload);
    }

    /**
     * @param AdminUser $adminUser
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(AdminUser $adminUser): void
    {
        // Prevent deletion of super admin users
        if ($adminUser->role && $adminUser->role->code === GeneralConstants::ROLES['SUPER_ADMIN']['name']) {
            throw new UnauthorizedException('Cannot delete super admin user.');
        }

        // Prevent self-deletion
        $currentUser = auth('admin')->user();
        if ($currentUser && $currentUser->uuid === $adminUser->uuid) {
            throw new UnauthorizedException('Cannot delete your own account.');
        }

        $adminUser->delete();
    }

    /**
     * Find admin user by email for authentication
     *
     * @param string $email
     * @return AdminUser|null
     */
    public function findByEmail(string $email): ?AdminUser
    {
        return $this->adminUser->with(['role'])
            ->where('email', $email)
            ->adminsOnly()
            ->first();
    }

    /**
     * Update last login timestamp
     *
     * @param AdminUser $adminUser
     * @return void
     */
    public function updateLastLogin(AdminUser $adminUser): void
    {
        $adminUser->update(['last_login_at' => now()]);
    }

    /**
     * Get admin users by role
     *
     * @param string $roleCode
     * @return Builder
     */
    public function getByRole(string $roleCode): Builder
    {
        return $this->adminUser->whereHas('role', function ($query) use ($roleCode) {
            $query->where('code', $roleCode);
        });
    }

    /**
     * Get available admin roles (excluding customer)
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableRoles()
    {
        return Role::where('code', '!=', GeneralConstants::ROLES['CUSTOMER']['name'])
            ->orderBy('name')
            ->get();
    }
}
