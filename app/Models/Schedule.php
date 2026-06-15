<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'event_uuid',
        'date_from',
        'date_to',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'event_uuid',
        'date_from',
        'date_to',
        'status',
        'created_by',
        'updated_by',
    ];

    const PUBLISHED_STATUS = 'published';

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
            $query = $query->whereHas('event', function ($q) use ($qKeyword) {
                $q->where('event_name', 'LIKE', "%$qKeyword%")
                  ->orWhere('event_description', 'LIKE', "%$qKeyword%");
            });
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['event_uuid'])) {
            $query = $query->where('event_uuid', $filters['event_uuid']);
        }

        if (isset($filters['date_from'])) {
            $query = $query->where('date_from', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query = $query->where('date_to', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @return Builder
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED_STATUS)
                 ->orderBy('date_from');
    }

    // Relationships
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function scheduleTimes(): HasMany
    {
        return $this->hasMany(ScheduleTime::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
