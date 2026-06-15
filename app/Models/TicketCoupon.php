<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketCoupon extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'ticket_uuid',
        'event_uuid',
        'event_ticket_coupon_uuid',
        'name',
        'qr_code',
        'status',
        'claimed_at',
        'scanned_by',
    ];

    /**
     * Scope for filtering records.
     *
     * @param Builder $query
     * @param array|null $filters
     * @return Builder
     */
    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['q']) && is_string($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($qry) use ($q) {
                $qry->where('name', 'LIKE', "%{$q}%")
                    ->orWhere('qr_code', 'LIKE', "%{$q}%");
            });
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        return $query;
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_uuid', 'uuid');
    }

    public function eventTicketCoupon(): BelongsTo
    {
        return $this->belongsTo(EventTicketCoupon::class, 'event_ticket_coupon_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'scanned_by', 'uuid');
    }
}
