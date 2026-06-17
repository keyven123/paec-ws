<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventSection extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'title',
        'description',
        'status',
        'display_order',
        'is_hidden',
        'created_by',
        'updated_by',
    ];

    const EVENT_SECTION_TYPES = [
        'FEATURED' => 'featured',
        'AMUSEMENT' => 'amusements',
        'AMUSEMENT_SINGULAR' => 'amusement',
        'OPEN_PASS' => 'open_pass',
        'NEW_EVENT' => 'new_event',
        'UPCOMING' => 'upcoming',
        'LIFE' => 'life',
        'EAT' => 'eat',
        'STAY' => 'stay',
        'TRAVEL' => 'travel',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'name',
        'title',
        'description',
        'status',
        'display_order',
        'is_hidden',
        'created_by',
        'updated_by',
    ];

    const FEATURED_SECTION = 'featured';
    const AMUSEMENT_SECTION = 'amusements';
    const OPEN_PASS_SECTION = 'open_pass';
    const NEW_EVENT_SECTION = 'new_event';
    const UPCOMING_SECTION = 'upcoming';
    const LIFE_SECTION = 'life';
    const EAT_SECTION = 'eat';
    const STAY_SECTION = 'stay';
    const TRAVEL_SECTION = 'travel';

    /**
     * PAEC fun-activity catalog sections. Featured activities stay in admin and
     * marketplace catalogs after promotion to the featured section.
     *
     * @return array<int, string>
     */
    public static function catalogSectionUuids(): array
    {
        return static::query()
            ->whereIn('name', [self::AMUSEMENT_SECTION, self::FEATURED_SECTION])
            ->pluck('uuid')
            ->all();
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
            $query = $query->where('name', 'LIKE', "%$qKeyword%")
                ->orWhere('title', 'LIKE', "%$qKeyword%")
                ->orWhere('description', 'LIKE', "%$qKeyword%");
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
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
        return $this->hasMany(Event::class, 'event_section_uuid', 'uuid');
    }
}
