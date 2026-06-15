<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

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
        'payment_order_id', // Existing field for backward compatibility
        'payment_provider', // Existing field
        'payment_id', // New field - generic payment ID from any provider
        'payment_data', // New field - JSON field to store raw payment response
        'order_number',
        'total_amount',
        'sub_total',
        'markup_type',
        'markup_value',
        'markup_amount',
        'markup_discount',
        'tax_amount',
        'discount',
        'status',
        'payment_status',
        'order_status',
        'paid_at',
        'promo_code_uuid',
        'promo_code_discount',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'sub_total' => 'decimal:2',
        'markup_value' => 'decimal:2',
        'markup_amount' => 'decimal:2',
        'markup_discount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'payment_data' => 'array',
        'paid_at' => 'datetime',
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
        'payment_order_id', // Existing field
        'payment_provider', // Existing field
        'payment_id', // New field - generic payment ID from any provider
        'payment_data', // New field - JSON field to store raw payment response
        'order_number',
        'total_amount',
        'sub_total',
        'markup_type',
        'markup_value',
        'markup_amount',
        'markup_discount',
        'tax_amount',
        'discount',
        'orders',
        'status',
        'payment_status',
        'order_status',
        'paid_at',
        'promo_code_uuid',
        'promo_code_discount',
        'created_by',
        'updated_by',
    ];

    const STATUS = [
        'ACTIVE' => 'active',
        'CANCELLED' => 'cancelled',
        'REFUNDED' => 'refunded',
    ];

    const PAYMENT_STATUS = [
        'PENDING' => 'pending',
        'PAID' => 'paid',
        'FAILED' => 'failed',
        'CANCELLED' => 'cancelled',
    ];

    const ORDER_STATUS = [
        'PENDING' => 'pending',
        'CONFIRMED' => 'confirmed',
        'CANCELLED' => 'cancelled',
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
            $query = $query->where('order_number', 'LIKE', "%$qKeyword%")
                ->orWhere('payment_order_id', 'LIKE', "%$qKeyword%")
                ->orWhere('payment_id', 'LIKE', "%$qKeyword%")
                ->orWhere('payment_status', 'LIKE', "%$qKeyword%")
                ->orWhere('payment_provider', 'LIKE', "%$qKeyword%")
                ->orWhere('order_status', 'LIKE', "%$qKeyword%")
                ->orWhereHas('event', function ($q) use ($qKeyword) {
                    $q->where('event_name', 'LIKE', "%$qKeyword%")
                        ->orWhere('event_description', 'LIKE', "%$qKeyword%")
                        ->orWhere('event_type', 'LIKE', "%$qKeyword%");
                })
                ->orWhereHas('user', function ($q) use ($qKeyword) {
                    $q->where('email', 'LIKE', "%$qKeyword%")
                        ->orWhere('first_name', 'LIKE', "%$qKeyword%")
                        ->orWhere('last_name', 'LIKE', "%$qKeyword%")
                        ->orWhereRaw(
                            config('database.default') == 'sqlite'
                            ? "first_name || ' ' || last_name LIKE ?"
                            : "LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ["%$qKeyword%"]
                        );
                });
        }

        if (isset($filters['organization_uuid'])) {
            $query = $query->where('organization_uuid', $filters['organization_uuid']);
        }

        if (isset($filters['user_uuid'])) {
            $query = $query->where('user_uuid', $filters['user_uuid']);
        }

        if (isset($filters['event_uuid'])) {
            $query = $query->where('event_uuid', $filters['event_uuid']);
        }

        if (isset($filters['transactionable_type'])) {
            $query = $query->where('transactionable_type', $filters['transactionable_type']);
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['payment_status'])) {
            $query = $query->where('payment_status', $filters['payment_status']);
        }

        if (isset($filters['order_status'])) {
            $query = $query->where('order_status', $filters['order_status']);
        }

        if (isset($filters['min_amount'])) {
            $query = $query->where('total_amount', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount'])) {
            $query = $query->where('total_amount', '<=', $filters['max_amount']);
        }

        if (! empty($filters['visit_start_date']) || ! empty($filters['visit_end_date'])) {
            $query->whereHas('transactionOrders', function ($orderQuery) use ($filters) {
                if (! empty($filters['visit_start_date'])) {
                    $orderQuery->whereDate('valid_until', '>=', $filters['visit_start_date']);
                }
                if (! empty($filters['visit_end_date'])) {
                    $orderQuery->whereDate('valid_until', '<=', $filters['visit_end_date']);
                }
            });
        }

        return $query;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS['PAID'];
    }

    /**
     * Merchant sales amount: net selling price minus platform commission (per export line).
     */
    public function merchantRevenueAmount(): float
    {
        $this->loadMissing('organization');

        $rate = ($this->organization !== null && $this->organization->commission_percentage !== null)
            ? (float) $this->organization->commission_percentage
            : Dataset::merchantCommissionPercent();

        return \App\Services\TicketPurchasePricingService::transactionMerchantSalesTotal($this, $rate);
    }

    /**
     * SQL expression for aggregating merchant revenue in analytics queries.
     */
    public static function merchantRevenueSqlAmount(?string $table = null): string
    {
        $prefix = $table !== null && $table !== '' ? $table.'.' : '';
        $net = 'ROUND(COALESCE('.$prefix.'sub_total, 0) - COALESCE('.$prefix.'discount, 0) - COALESCE('.$prefix.'promo_code_discount, 0), 2)';

        if (config('database.default') === 'sqlite') {
            return "CASE WHEN {$net} < 0 THEN 0 ELSE {$net} END";
        }

        return "GREATEST(0, {$net})";
    }

    public function isCancelled(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS['CANCELLED'];
    }

    public function isPending(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS['PENDING'];
    }

    /**
     * Limit to a given polymorphic subject type (morph alias, e.g. 'event').
     */
    public function scopeOfType(Builder $query, string $alias): Builder
    {
        return $query->where('transactionable_type', $alias);
    }

    /**
     * Limit to event/ticket transactions. Uses event_uuid presence so legacy
     * rows created before transactionable_type existed are still included.
     */
    public function scopeEventsOnly(Builder $query): Builder
    {
        return $query->whereNotNull('event_uuid');
    }

    public function scopeByOrganization(Builder $query): Builder
    {
        if (auth('admin')->user() && !auth('admin')->user()->role->is_admin) {
            return $query->where('organization_uuid', auth('admin')->user()->organization_uuid);
        }
        return $query;
    }

    /**
     * Get the user that owns the transaction
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the polymorphic subject of the transaction (Event, VenueInquiry, ...).
     * Uses transactionable_uuid as the owner key since models use uuid PKs.
     * @return MorphTo
     */
    public function transactionable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'transactionable_type', 'transactionable_uuid', 'uuid');
    }

    /**
     * Get the event that owns the transaction. Kept for backward compatibility;
     * reads the denormalized event_uuid column and is null for non-event
     * transactions.
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    /**
     * Get the creator that owns the transaction
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the updater that owns the transaction
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tickets that owns the transaction
     * @return HasMany
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get the schedule that owns the transaction
     * @return BelongsTo
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * Get the schedule time that owns the transaction
     * @return BelongsTo
     */
    public function scheduleTime(): BelongsTo
    {
        return $this->belongsTo(ScheduleTime::class);
    }

    /**
     * Get the organization that owns the transaction
     * @return BelongsTo
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope to filter transactions by organization
     * @param Builder $query
     * @param string $organizationUuid
     * @return Builder
     */
    public function scopeOwnedBy(Builder $query, string $organizationUuid): Builder
    {
        return $query->where('organization_uuid', $organizationUuid);
    }

    /**
     * Scope to filter transactions by user
     * @param Builder $query
     * @param string $userUuid
     * @return Builder
     */
    public function scopeOwnedByUser(Builder $query, string $userUuid): Builder
    {
        return $query->where('user_uuid', $userUuid);
    }

    /**
     * Get the transaction orders that owns the transaction
     * @return HasMany
     */
    public function transactionOrders(): HasMany
    {
        return $this->hasMany(TransactionOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_uuid', 'uuid')->withTrashed();
    }

    public function affiliateConversion(): HasOne
    {
        return $this->hasOne(AffiliateConversion::class, 'transaction_uuid', 'uuid');
    }

    public function affiliatePartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_partner_uuid', 'uuid');
    }

    public function commissionLedger(): MorphOne
    {
        return $this->morphOne(TransactionCommission::class, 'accountable')
            ->where('transaction_type', TransactionCommission::TYPE['TRANSACTION']);
    }

    public function transactionCompliances(): HasMany
    {
        return $this->hasMany(TransactionCompliance::class, 'transaction_uuid', 'uuid');
    }
}
