<?php

namespace App\Models;

use App\Constants\GeneralConstants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'organization_uuid',
        'code',
        'description',
        'activityable_id',
        'activityable_type',
        'discount_type',
        'discount_value',
        'is_unlimited',
        'max_use',
        'used_count',
        'usable_from',
        'usable_to',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'is_unlimited' => 'boolean',
        'max_use' => 'integer',
        'used_count' => 'integer',
        'usable_from' => 'datetime',
        'usable_to' => 'datetime',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'organization_uuid',
        'code',
        'description',
        'activityable_id',
        'activityable_type',
        'discount_type',
        'discount_value',
        'is_unlimited',
        'max_use',
        'used_count',
        'usable_from',
        'usable_to',
        'status',
        'created_by',
        'updated_by',
    ];

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
            $query = $query->where(function ($q) use ($qKeyword) {
                $q->where('code', 'LIKE', "%$qKeyword%")
                    ->orWhere('description', 'LIKE', "%$qKeyword%");
            });
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['organization_uuid'])) {
            $query = $query->where('organization_uuid', $filters['organization_uuid']);
        }

        if (isset($filters['discount_type'])) {
            $query = $query->where('discount_type', $filters['discount_type']);
        }

        if (isset($filters['is_unlimited'])) {
            $query = $query->where('is_unlimited', $filters['is_unlimited']);
        }

        if (isset($filters['activityable_type'])) {
            $query = $query->where('activityable_type', $filters['activityable_type']);
        }

        if (isset($filters['activityable_id'])) {
            $query = $query->where('activityable_id', $filters['activityable_id']);
        }

        return $query;
    }

    // Relationships
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    /**
     * Get the parent activityable model (Event, etc.)
     * @return MorphTo
     */
    public function activityable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'uuid');
    }

    public function updater()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'uuid');
    }
}
