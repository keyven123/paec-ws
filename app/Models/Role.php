<?php

namespace App\Models;

use App\Constants\GeneralConstants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'organization_uuid',
        'name',
        'code',
        'is_admin',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    /**
     * Get the permissions for the role
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_uuid', 'permission_uuid');
    }

    /**
     * Get the users for the role
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the users for the role
     */
    public function adminUsers()
    {
        return $this->hasMany(AdminUser::class);
    }

    public function revokePermissionTo(string $permissionCode): void
    {
        $permission = Permission::where('code', $permissionCode)->first();
        if ($permission) {
            $this->permissions()->where('permission_uuid', $permission->uuid)->delete();
        }
    }

    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['q'])) {
            $qKeyword = $filters['q'];
            $query->where(function (Builder $inner) use ($qKeyword) {
                $inner->where('name', 'LIKE', "%$qKeyword%")
                    ->orWhere('code', 'LIKE', "%$qKeyword%");
            });
        }

        if (isset($filters['is_admin'])) {
            $query->where('is_admin', $filters['is_admin']);
        }

        if (!empty($filters['organization_uuid'])) {
            $query->where('organization_uuid', $filters['organization_uuid']);
        }

        return $query;
    }

    public function scopeVisibleToOrganizer(Builder $query, ?string $organizationUuid): Builder
    {
        return $query->where(function (Builder $inner) use ($organizationUuid) {
            $inner->where(function (Builder $globalRoles) {
                $globalRoles->where('is_admin', false)
                    ->where('code', '!=', GeneralConstants::ROLES['CUSTOMER']['name']);
            });

            if ($organizationUuid) {
                $inner->orWhere('organization_uuid', $organizationUuid);
            }
        });
    }
}
