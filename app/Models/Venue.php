<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'place_uuid',
        'name',
        'code',
        'type',
        'image_uuid',
        'status',
        'tickets',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tickets' => 'array',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'place_uuid',
        'name',
        'code',
        'type',
        'image_uuid',
        'tickets',
        'status',
        'created_by',
        'updated_by',
    ];

    const TYPES = [
        'MUSICAL' => 'musical',
        'THEATRE' => 'theatre',
        'SPORT' => 'sport',
        'OTHERS' => 'others',
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
                ->orWhere('code', 'LIKE', "%$qKeyword%")
                ->orWhere('type', 'LIKE', "%$qKeyword%");
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query = $query->where('type', $filters['type']);
        }

        if (isset($filters['code'])) {
            $query = $query->where('code', $filters['code']);
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
        return $this->hasMany(Event::class, 'venue_uuid', 'uuid');
    }

    public function image()
    {
        return $this->belongsTo(Upload::class, 'image_uuid', 'uuid');
    }

    public function venueSeats()
    {
        return $this->hasMany(VenueSeat::class, 'venue_uuid', 'uuid');
    }
}
