<?php

namespace App\Models;

use App\Constants\GeneralConstants;
use Carbon\Carbon;
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
        'operation_days',
        'operation_custom_days',
        'operation_hours_from',
        'operation_hours_to',
        'available_from',
        'available_to',
        'feature_on_flash_sale',
        'flash_sale_from',
        'flash_sale_to',
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

    public const OPERATION_DAYS = [
        'ANY' => 'any',
        'WEEKDAYS' => 'weekdays',
        'WEEKENDS' => 'weekends',
        'CUSTOM' => 'custom',
    ];

    /** ISO weekday keys used in operation_custom_days (1=Mon … 7=Sun). */
    public const OPERATION_WEEKDAY_KEYS = [
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
        7 => 'sun',
    ];

    public const OPERATION_WEEKDAY_LABELS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'markup_value' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'is_bundle' => 'boolean',
        'bundle_quantity' => 'integer',
        'bundle_tickets' => 'array',
        'operation_custom_days' => 'array',
        'available_from' => 'datetime',
        'available_to' => 'datetime',
        'feature_on_flash_sale' => 'boolean',
        'flash_sale_from' => 'datetime',
        'flash_sale_to' => 'datetime',
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
        'operation_days',
        'operation_custom_days',
        'operation_hours_from',
        'operation_hours_to',
        'available_from',
        'available_to',
        'feature_on_flash_sale',
        'flash_sale_from',
        'flash_sale_to',
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

    /**
     * Whether this ticket can be purchased right now (sales window).
     * Flash-featured tickets use flash_sale_from/to; others use available_from/to.
     */
    public function isCurrentlyOnSale(?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now((string) config('app.timezone', 'Asia/Manila'));

        if ($this->feature_on_flash_sale) {
            if ($this->flash_sale_from && $now->lt($this->flash_sale_from)) {
                return false;
            }

            if ($this->flash_sale_to && $now->gt($this->flash_sale_to)) {
                return false;
            }

            return true;
        }

        if ($this->available_from && $now->lt($this->available_from)) {
            return false;
        }

        if ($this->available_to && $now->gt($this->available_to)) {
            return false;
        }

        return true;
    }

    /**
     * Whether this ticket can be booked for the given visit calendar day.
     * Compares date portions of available_from / available_to (inclusive).
     * Leave those empty to allow any visit date (e.g. during a flash sale).
     */
    public function isAvailableForVisitDate(string $visitDate): bool
    {
        $date = Carbon::parse($visitDate)->toDateString();

        if ($this->available_from && $date < $this->available_from->toDateString()) {
            return false;
        }

        if ($this->available_to && $date > $this->available_to->toDateString()) {
            return false;
        }

        if (! $this->allowsVisitDayOfWeek($date)) {
            return false;
        }

        return true;
    }

    /**
     * Weekday / weekend / custom-day restriction from Operations settings.
     */
    public function allowsVisitDayOfWeek(string $visitDate): bool
    {
        $days = $this->operation_days ?: self::OPERATION_DAYS['ANY'];
        if ($days === self::OPERATION_DAYS['ANY']) {
            return true;
        }

        $dayOfWeek = Carbon::parse($visitDate, 'Asia/Manila')->dayOfWeekIso; // 1=Mon … 7=Sun
        $isWeekend = $dayOfWeek >= 6;

        if ($days === self::OPERATION_DAYS['WEEKDAYS']) {
            return ! $isWeekend;
        }

        if ($days === self::OPERATION_DAYS['WEEKENDS']) {
            return $isWeekend;
        }

        if ($days === self::OPERATION_DAYS['CUSTOM']) {
            $allowed = $this->normalizedCustomDays();
            if ($allowed === []) {
                return false;
            }
            $key = self::OPERATION_WEEKDAY_KEYS[$dayOfWeek] ?? null;

            return $key !== null && in_array($key, $allowed, true);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function normalizedCustomDays(): array
    {
        $raw = $this->operation_custom_days;
        if (! is_array($raw)) {
            return [];
        }

        $valid = array_values(self::OPERATION_WEEKDAY_KEYS);

        return array_values(array_unique(array_filter(
            array_map(static fn ($day) => strtolower(trim((string) $day)), $raw),
            static fn (string $day) => in_array($day, $valid, true),
        )));
    }

    public function visitDayUnavailableMessage(string $visitDate, ?string $ticketName = null): string
    {
        $name = $ticketName ?: ($this->name ?: 'This ticket');
        $mode = $this->operation_days ?: self::OPERATION_DAYS['ANY'];

        if ($mode === self::OPERATION_DAYS['WEEKDAYS']) {
            return "{$name} is available on weekdays only.";
        }

        if ($mode === self::OPERATION_DAYS['WEEKENDS']) {
            return "{$name} is available on weekends only.";
        }

        if ($mode === self::OPERATION_DAYS['CUSTOM']) {
            $dayOfWeek = Carbon::parse($visitDate, 'Asia/Manila')->dayOfWeekIso;
            $key = self::OPERATION_WEEKDAY_KEYS[$dayOfWeek] ?? null;
            $label = $key ? (self::OPERATION_WEEKDAY_LABELS[$key] ?? $key) : 'this day';

            return "{$name} is not available on {$label}.";
        }

        return "{$name} is unavailable for the selected date of visit.";
    }

    /**
     * Whether a HH:MM visit time falls inside operation hours (inclusive).
     * No hours configured → any time is allowed.
     */
    public function allowsVisitTime(?string $visitTime): bool
    {
        $from = $this->formatOperationTime($this->operation_hours_from);
        $to = $this->formatOperationTime($this->operation_hours_to);

        if (! $from && ! $to) {
            return true;
        }

        if ($visitTime === null || $visitTime === '') {
            return false;
        }

        $time = substr($visitTime, 0, 5);
        if ($from && $time < $from) {
            return false;
        }
        if ($to && $time > $to) {
            return false;
        }

        return true;
    }

    public function formatOperationTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    public function hasOperationHours(): bool
    {
        return $this->formatOperationTime($this->operation_hours_from) !== null
            || $this->formatOperationTime($this->operation_hours_to) !== null;
    }

    /**
     * On sale now and valid for the selected visit date.
     */
    public function isAvailableForPurchase(string $visitDate, ?Carbon $now = null): bool
    {
        return $this->isCurrentlyOnSale($now) && $this->isAvailableForVisitDate($visitDate);
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
