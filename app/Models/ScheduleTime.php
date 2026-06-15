<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleTime extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'schedule_uuid',
        'time_start',
        'time_end',
        'status',
        'created_by',
        'updated_by',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'schedule_uuid',
        'time_start',
        'time_end',
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
            $query = $query->whereHas('schedule.event', function ($q) use ($qKeyword) {
                $q->where('event_name', 'LIKE', "%$qKeyword%")
                  ->orWhere('event_description', 'LIKE', "%$qKeyword%");
            });
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['schedule_uuid'])) {
            $query = $query->where('schedule_uuid', $filters['schedule_uuid']);
        }

        if (isset($filters['time_start'])) {
            $query = $query->where('time_start', '>=', $filters['time_start']);
        }

        if (isset($filters['time_end'])) {
            $query = $query->where('time_end', '<=', $filters['time_end']);
        }

        return $query;
    }

    // Relationships
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function eventTickets(): HasMany
    {
        return $this->hasMany(EventTicket::class, 'schedule_time_uuid', 'uuid');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return Builder
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED_STATUS)
                 ->orderBy('time_start');
    }
}
