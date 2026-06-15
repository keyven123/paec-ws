<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSeat extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'ticket_uuid',
        'venue_uuid',
        'venue_seat_uuid',
        'col',
        'row',
        'seat_no',
        'category',
        'color',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'row' => 'integer',
        'seat_no' => 'integer',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'ticket_uuid',
        'venue_uuid',
        'venue_seat_uuid',
        'col',
        'row',
        'seat_no',
        'category',
        'color',
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
            $query = $query->where('col', 'LIKE', "%$qKeyword%")
                ->orWhere('seat_no', 'LIKE', "%$qKeyword%");
        }

        if (isset($filters['ticket_uuid'])) {
            $query = $query->where('ticket_uuid', $filters['ticket_uuid']);
        }

        if (isset($filters['venue_seat_uuid'])) {
            $query = $query->where('venue_seat_uuid', $filters['venue_seat_uuid']);
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
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_uuid', 'uuid');
    }

    public function venueSeat(): BelongsTo
    {
        return $this->belongsTo(VenueSeat::class, 'venue_seat_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_uuid', 'uuid');
    }
}
