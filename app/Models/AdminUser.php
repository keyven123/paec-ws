<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use App\Constants\GeneralConstants;

class AdminUser extends Authenticatable implements JWTSubject
{
    use HasUuids;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';
    protected $table = 'admin_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_uuid',
        'organization_uuid',
        'email',
        'password',
        'first_name',
        'middle_name',
        'last_name',
        'phone_number',
        'status',
        'accepted_terms',
        'accepted_terms_at',
        'is_first_time_login',
        'email_verified_at',
        'last_login_at',
        'is_migrated',
        'created_by',
        'updated_by',
    ];

    const DATA = [
        'role_uuid',
        'organization_uuid',
        'email',
        'password',
        'first_name',
        'middle_name',
        'last_name',
        'phone_number',
        'status',
        'accepted_terms',
        'accepted_terms_at',
        'is_first_time_login',
        'email_verified_at',
        'last_login_at',
        'is_migrated',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        $role = $this->role;

        $permissions = [];
        if ($role) {
            $rolePermissions = \App\Models\RolePermission::where('role_uuid', $role->uuid)
                ->with('permission')
                ->get();

            foreach ($rolePermissions as $rp) {
                $permissions[] = $rp->access;
            }
        }

        return [
            'role' => $role ? $role->code : null,
            'permissions' => $permissions,
            'user_uuid' => $this->uuid,
            'role_uuid' => $role ? $role->uuid : null,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_first_time_login' => 'boolean',
        ];
    }

    /**
     * @param string $value
     * @return void
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Hash::make($value);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . ($this->middle_name ? $this->middle_name . ' ' : '') . $this->last_name);
    }

    /**
     * Get the role for the admin user
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_uuid', 'uuid');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the permissions for the admin user through role
     */
    public function permissions()
    {
        return $this->role->permissions();
    }

    public function passwordResets()
    {
        return $this->morphMany(PasswordReset::class, 'resettable');
    }

    /**
     * Check if admin user has specific permission
     */
    public function hasPermission(string $permissionCode): bool
    {
        if (!$this->role) {
            return false;
        }

        // Check if the permission code includes access (e.g., 'users-view')
        if (strpos($permissionCode, '-') !== false) {
            // Full permission string, check access field
            return \App\Models\RolePermission::where('role_uuid', $this->role->uuid)
                ->where('access', $permissionCode)
                ->exists();
        } else {
            // Base permission code, check permission relationship
            return $this->role->permissions()
                ->where('code', $permissionCode)
                ->exists();
        }
    }

    /**
     * Check if admin user has any of the given permissions
     */
    public function hasAnyPermission(array $permissionCodes): bool
    {
        if (!$this->role) {
            return false;
        }

        // Get all role permissions with access field
        $rolePermissions = \App\Models\RolePermission::where('role_uuid', $this->role->uuid)->get();

        foreach ($rolePermissions as $rolePermission) {
            if (in_array($rolePermission->access, $permissionCodes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user is an admin (any role except customer)
     */
    public function isAdmin(): bool
    {
        if (!$this->role) {
            return false;
        }

        return $this->role->code !== GeneralConstants::ROLES['CUSTOMER']['name'];
    }

    /**
     * Scope for filtering records
     * @param Builder $query
     * @param array|null $filters
     * @return Builder
     */
    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['q'])) {
            $qKeyword = $filters['q'];
            $query = $query->where('email', 'LIKE', "%$qKeyword%")
                ->orWhereRaw(
                    config('database.default') == 'sqlite'
                        ? "first_name || ' ' || last_name LIKE ?"
                        : "LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?",
                    ["%$qKeyword%"]
                );
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['role_uuid'])) {
            $query = $query->where('role_uuid', $filters['role_uuid']);
        }

        // Only show admin users (exclude customers)
        $query = $query->whereHas('role', function ($q) {
            $q->where('code', '!=', GeneralConstants::ROLES['CUSTOMER']['name']);
        });

        if (isset($filters['is_admin'])) {
            if ($filters['is_admin']) {
                $query = $query->whereNull('organization_uuid');
            } else {
                $query = $query->whereNotNull('organization_uuid');
            }
        }

        if (!empty($filters['organization_uuid'])) {
            $query = $query->where('organization_uuid', $filters['organization_uuid']);
        }

        return $query;
    }

    /**
     * Scope to get only admin users (non-customers)
     */
    public function scopeAdminsOnly(Builder $query): Builder
    {
        return $query->whereHas('role', function ($q) {
            $q->where('code', '!=', GeneralConstants::ROLES['CUSTOMER']['name']);
        });
    }

    // Add relationships here
    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'uuid');
    }

    public function updater()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'uuid');
    }
}
