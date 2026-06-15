<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Notifiable;

class Organization extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;
    use Notifiable;

    public const BUSINESS_TYPE_SOLE_PROPRIETORSHIP = 'sole_proprietorship';
    public const BUSINESS_TYPE_PARTNERSHIP = 'partnership';
    public const BUSINESS_TYPE_CORPORATION = 'corporation';
    public const BUSINESS_TYPE_NONPROFIT = 'nonprofit';
    public const BUSINESS_TYPE_GOVERNMENT = 'government';
    public const BUSINESS_TYPE_INDIVIDUAL_SELLER = 'individual_seller';

    public const BUSINESS_TYPES = [
        self::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
        self::BUSINESS_TYPE_PARTNERSHIP,
        self::BUSINESS_TYPE_CORPORATION,
        self::BUSINESS_TYPE_NONPROFIT,
        self::BUSINESS_TYPE_GOVERNMENT,
        self::BUSINESS_TYPE_INDIVIDUAL_SELLER,
    ];

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'image_uuid',
        'business_type',
        'name',
        'representative_first_name',
        'representative_last_name',
        'address',
        'contact_number',
        'email',
        'tin',
        'description',
        'commission_percentage',
        'secret',
        'secret_expired_at',
        'approved_by',
        'approved_at',
        'send_invite_count',
        'status',
        'payment_methods'
    ];

    protected $casts = [
        'payment_methods' => 'array',
    ];

    const DATA = [
        'image_uuid',
        'business_type',
        'name',
        'representative_first_name',
        'representative_last_name',
        'address',
        'contact_number',
        'email',
        'tin',
        'description',
        'commission_percentage',
        'secret',
        'secret_expired_at',
        'approved_by',
        'approved_at',
        'send_invite_count',
        'status',
        'payment_methods'
    ];

    /**
     * Route notifications for the mail channel.
     * @param Notification $notification
     * @return array|string
     */
    public function routeNotificationForMail(Notification $notification): array|string
    {
        return $this->email;
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            if (blank($organization->business_type)) {
                $organization->business_type = self::BUSINESS_TYPE_SOLE_PROPRIETORSHIP;
            }
        });
    }

    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['q']) && $filters['q'] !== '') {
            $term = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('representative_first_name', 'LIKE', $term)
                    ->orWhere('representative_last_name', 'LIKE', $term)
                    ->orWhere('email', 'LIKE', $term)
                    ->orWhere('description', 'LIKE', $term)
                    ->orWhere('address', 'LIKE', $term)
                    ->orWhereHas('banks', function (Builder $bankQuery) use ($term) {
                        $bankQuery->where('bank_account_name', 'LIKE', $term)
                            ->orWhere('bank_account_number', 'LIKE', $term);
                    });
            });
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['statuses']) && is_array($filters['statuses']) && $filters['statuses'] !== []) {
            $query = $query->whereIn('status', $filters['statuses']);
        }

        return $query;
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by', 'uuid');
    }

    public function adminUser(): HasMany
    {
        return $this->hasMany(AdminUser::class, 'organization_uuid', 'uuid');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'image_uuid', 'uuid');
    }

    public function merchantPayoutRequests(): HasMany
    {
        return $this->hasMany(MerchantPayoutRequest::class, 'organization_uuid', 'uuid');
    }

    public function banks(): HasMany
    {
        return $this->hasMany(OrganizationBank::class, 'organization_uuid', 'uuid')
            ->orderByDesc('is_default')
            ->orderBy('created_at');
    }

    public function defaultBank(): ?OrganizationBank
    {
        if ($this->relationLoaded('banks')) {
            return $this->banks->firstWhere('is_default', true) ?? $this->banks->first();
        }

        return $this->banks()
            ->where('is_default', true)
            ->first()
            ?? $this->banks()->orderBy('created_at')->first();
    }
}
