<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CareerPosition extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'department',
        'location',
        'work_setup',
        'employment_type',
        'description',
        'requirements',
        'status',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_PUBLISHED = 'published';

    const EMPLOYMENT_TYPES = [
        'full-time',
        'part-time',
        'contract',
        'internship',
        'freelance',
    ];

    const WORK_SETUPS = [
        'on-site',
        'hybrid',
        'work-from-home',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(CareerApplication::class, 'career_position_uuid', 'uuid');
    }

    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['work_setup'])) {
            $query->where('work_setup', $filters['work_setup']);
        }

        if (!empty($filters['q'])) {
            $keyword = $filters['q'];
            $query->where(function ($sub) use ($keyword) {
                $sub->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('department', 'LIKE', "%{$keyword}%")
                    ->orWhere('location', 'LIKE', "%{$keyword}%");
            });
        }

        return $query;
    }
}
