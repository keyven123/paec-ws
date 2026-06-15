<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TempTransaction extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'transactionable_type',
        'transactionable_uuid',
        'event_uuid',
        'event_location_uuid',
        'schedule_uuid',
        'schedule_time_uuid',
        'organization_uuid',
        'affiliate_partner_uuid',
        'total_amount',
        'sub_total',
        'markup_type',
        'markup_value',
        'markup_amount',
        'markup_discount',
        'tax_amount',
        'discount',
        'promo_code_uuid',
        'promo_code_discount',
        'valid_until',
        'marketing_followup_sent_at',
    ];

    protected $casts = [
        'valid_until' => 'datetime',
        'marketing_followup_sent_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'sub_total' => 'decimal:2',
        'markup_value' => 'decimal:2',
        'markup_amount' => 'decimal:2',
        'markup_discount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'promo_code_discount' => 'decimal:2',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'user_uuid',
        'transactionable_type',
        'transactionable_uuid',
        'event_uuid',
        'event_location_uuid',
        'schedule_uuid',
        'schedule_time_uuid',
        'organization_uuid',
        'affiliate_partner_uuid',
        'total_amount',
        'sub_total',
        'markup_type',
        'markup_value',
        'markup_amount',
        'markup_discount',
        'tax_amount',
        'discount',
        'promo_code_uuid',
        'promo_code_discount',
        'valid_until',
        'marketing_followup_sent_at',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * Polymorphic subject of the hold (Event, VenueInquiry, ...).
     */
    public function transactionable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'transactionable_type', 'transactionable_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_uuid', 'uuid');
    }

    public function scheduleTime(): BelongsTo
    {
        return $this->belongsTo(ScheduleTime::class, 'schedule_time_uuid', 'uuid');
    }

    public function eventTicket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'event_ticket_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function tempTransactionOrders(): HasMany
    {
        return $this->hasMany(TempTransactionOrder::class);
    }

    /**
     * Whether this hold reserves specific seats (seat-selection event or orders with seats).
     */
    public function hasSeatReservation(): bool
    {
        if ($this->event?->event_config === Event::EVENT_CONFIGS['SEAT_SELECTION']) {
            return true;
        }

        foreach ($this->tempTransactionOrders as $order) {
            $seats = $order->seats;
            if (is_array($seats) && count($seats) > 0) {
                return true;
            }
        }

        return false;
    }

    public function scopeOwnedBy(Builder $query, string $userUuid): Builder
    {
        return $query->where('user_uuid', $userUuid);
    }

    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['event_uuid'])) {
            $query = $query->where('event_uuid', $filters['event_uuid']);
        }

        if (isset($filters['schedule_uuid'])) {
            $query = $query->where('schedule_uuid', $filters['schedule_uuid']);
        }

        if (isset($filters['schedule_time_uuid'])) {
            $query = $query->where('schedule_time_uuid', $filters['schedule_time_uuid']);
        }

        if (isset($filters['voucher_uuid'])) {
            $query = $query->where('voucher_uuid', $filters['voucher_uuid']);
        }

        return $query;
    }
}
