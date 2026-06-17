<?php

namespace App\Models;

use App\Constants\PermissionRoleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'code',
        'module',
        'available_access',
        'role_scope',
        'description',
    ];

    protected $casts = [
        'available_access' => 'array',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get the roles for the permission
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_uuid', 'role_uuid');
    }

    public function syncRolePermissions(Role $role, array $permissions): void
    {
        $this->roles()->attach($role->uuid, ['access' => $permissions['access']]);
    }

    public function scopeForAdminRole(Builder $query): Builder
    {
        return $query->whereIn('role_scope', PermissionRoleScope::allowedForAdminRole());
    }

    public function scopeForOrganizerRole(Builder $query): Builder
    {
        return $query->whereIn('role_scope', PermissionRoleScope::allowedForOrganizerRole());
    }

    public function scopeForRoleType(Builder $query, bool $isAdmin): Builder
    {
        return $isAdmin ? $query->forAdminRole() : $query->forOrganizerRole();
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('role_scope', PermissionRoleScope::SHARED);
    }
}
