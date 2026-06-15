<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'code',
        'type',
        'status',
        'created_by',
        'updated_by',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'name',
        'code',
        'type',
        'status',
        'created_by',
        'updated_by',
    ];

    // Category options
    const CATEGORIES = [
        'CONFERENCE' => 'Conference',
        'WORKSHOP' => 'Workshop',
        'SEMINAR' => 'Seminar',
        'NETWORKING' => 'Networking',
        'CONCERT' => 'Concert',
        'FESTIVAL' => 'Festival',
        'SPORTS' => 'Sports',
        'EXHIBITION' => 'Exhibition',
        'OTHERS' => 'Others',
    ];

    const CATEGORY_CODES = [
        'CONFERENCE' => 'conference',
        'WORKSHOP' => 'workshop',
        'SEMINAR' => 'seminar',
        'NETWORKING' => 'networking',
        'CONCERT' => 'concert',
        'FESTIVAL' => 'festival',
        'SPORTS' => 'sports',
        'EXHIBITION' => 'exhibition',
        'OTHERS' => 'others',
    ];

    const TYPES = [
        'EVENT' => 'event',
        'FUN' => 'fun',
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
            $query = $query->where('name', 'LIKE', "%$qKeyword%")
                ->orWhere('code', 'LIKE', "%$qKeyword%");
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['code'])) {
            $query = $query->where('code', $filters['code']);
        }

        if (isset($filters['type'])) {
            $query = $query->where('type', $filters['type']);
        }

        return $query;
    }

    // Relationships
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'category_uuid', 'uuid');
    }
}
