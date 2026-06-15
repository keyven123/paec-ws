<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VenueSeat extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'venue_uuid',
        'col',
        'row',
        'seat_no',
        'category',
        'color',
        'order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'col' => 'integer',
        'seat_no' => 'integer',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'venue_uuid',
        'col',
        'row',
        'seat_no',
        'category',
        'color',
        'order',
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
            $query = $query->where(function ($query) use ($qKeyword) {
                $query->where('col', 'LIKE', "%$qKeyword%")
                    ->orWhere('seat_no', 'LIKE', "%$qKeyword%")
                    ->orWhere('row', 'LIKE', "%$qKeyword%");
            });
        }

        if (isset($filters['venue_uuid'])) {
            $query = $query->where('venue_uuid', $filters['venue_uuid']);
        }

        if (isset($filters['category'])) {
            $query = $query->where('category', $filters['category']);
        }

        if (isset($filters['color'])) {
            $query = $query->where('color', $filters['color']);
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['col'])) {
            $query = $query->where('col', $filters['col']);
        }

        if (isset($filters['row'])) {
            $query = $query->where('row', $filters['row']);
        }

        return $query;
    }

    // Relationships
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function ticketSeats(): HasMany
    {
        return $this->hasMany(TicketSeat::class, 'venue_seat_uuid', 'uuid');
    }
}
