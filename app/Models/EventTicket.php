<?php

namespace App\Models;

use App\Constants\GeneralConstants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTicket extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'event_uuid',
        'schedule_uuid',
        'schedule_time_uuid',
        'code',
        'name',
        'description',
        'price',
        'markup_type',
        'markup_value',
        'is_bundle',
        'bundle_quantity',
        'discount_type',
        'discount_value',
        'bundle_tickets',
        'visit_policy',
        'validity_days',
        'available_from',
        'available_to',
        'display_order',
        'max_ticket',
        'sold_ticket',
        'ticket_limit_per_user',
        'is_virtual',
        'virtual_event_url',
        'status',
        'bg_color',
        'is_unlimited',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'markup_value' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'is_bundle' => 'boolean',
        'bundle_quantity' => 'integer',
        'bundle_tickets' => 'array',
        'available_from' => 'datetime',
        'available_to' => 'datetime',
        'display_order' => 'integer',
        'validity_days' => 'integer',
        'max_ticket' => 'integer',
        'sold_ticket' => 'integer',
        'ticket_limit_per_user' => 'integer',
        'is_virtual' => 'boolean',
        'is_unlimited' => 'boolean',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'event_uuid',
        'schedule_uuid',
        'schedule_time_uuid',
        'code',
        'name',
        'description',
        'price',
        'markup_type',
        'markup_value',
        'is_bundle',
        'bundle_quantity',
        'discount_type',
        'discount_value',
        'bundle_tickets',
        'visit_policy',
        'validity_days',
        'available_from',
        'available_to',
        'display_order',
        'max_ticket',
        'sold_ticket',
        'ticket_limit_per_user',
        'is_virtual',
        'virtual_event_url',
        'status',
        'bg_color',
        'is_unlimited',
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
            $query = $query->where(function ($q) use ($qKeyword) {
                $q->where('name', 'LIKE', "%$qKeyword%")
                    ->orWhere('description', 'LIKE', "%$qKeyword%")
                    ->orWhere('code', 'LIKE', "%$qKeyword%");
            });
        }

        if (isset($filters['event_uuid'])) {
            $query = $query->where('event_uuid', $filters['event_uuid']);
        }

        if (isset($filters['schedule_time_uuid'])) {
            $query = $query->where('schedule_time_uuid', $filters['schedule_time_uuid']);
        }

        if (isset($filters['is_bundle'])) {
            $query = $query->where('is_bundle', $filters['is_bundle']);
        }

        if (isset($filters['available_from'])) {
            $query = $query->where('available_from', '>=', $filters['available_from']);
        }

        if (isset($filters['available_to'])) {
            $query = $query->where('available_to', '<=', $filters['available_to']);
        }

        if (isset($filters['min_price'])) {
            $query = $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query = $query->where('price', '<=', $filters['max_price']);
        }

        return $query;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE']);
    }

    // Relationships
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function transactionOrders(): HasMany
    {
        return $this->hasMany(TransactionOrder::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(EventTicketCoupon::class, 'event_ticket_uuid', 'uuid');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'event_ticket_uuid', 'uuid');
    }
}
