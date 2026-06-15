<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasUuids;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_uuid',
        'profile_image_uuid',
        'email',
        'password',
        'first_name',
        'middle_name',
        'last_name',
        'phone_number',
        'birth_date',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country',
        'status',
        'is_first_time_login',
        'email_verified_at',
        'marketing_consent',
        'marketing_consent_date',
        'terms_accepted_at',
        'qr_code',
        'is_migrated',
        'provider',
        'provider_id',
        'avatar',
        'terms_accepted_at',
        'is_migrated',
        'created_by',
        'updated_by',
        'remember_token',
    ];

    const DATA = [
        'role_uuid',
        'profile_image_uuid',
        'email',
        'password',
        'first_name',
        'middle_name',
        'last_name',
        'phone_number',
        'birth_date',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country',
        'status',
        'marketing_consent',
        'terms_accepted_at',
        'is_migrated',
        'provider',
        'provider_id',
        'avatar',
        'terms_accepted_at',
        'is_migrated',
        'remember_token',
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
            'password' => 'hashed',
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
        return $this->first_name . ' ' . $this->middle_name . ' ' . $this->last_name;
    }

    public function profileImage()
    {
        return $this->belongsTo(Upload::class, 'profile_image_uuid', 'uuid');
    }

    /**
     * Get the role for the user
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_uuid', 'uuid');
    }

    public function userAffiliate(): HasOne
    {
        return $this->hasOne(UserAffiliate::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the permissions for the user through role
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
     * Get the transactions for the user
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the tickets for the user
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'user_uuid', 'uuid');
    }

    /**
     * Check if user has specific permission
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
     * Check if user has any of the given permissions
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
                    : "LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ["%$qKeyword%"]
                );
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['role_id'])) {
            $role = Role::find($filters['role_id']);
            $query = $query->whereHas('role', function ($q) use ($role) {
                $q->where('uuid', $role->uuid);
            });
        }

        if (isset($filters['affiliate_status'])) {
            $query->whereHas('userAffiliate', function ($q) use ($filters) {
                $q->where('affiliate_status', $filters['affiliate_status']);
            });
        }

        return $query;
    }
}
