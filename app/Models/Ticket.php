<?php

namespace App\Models;

use App\Constants\GeneralConstants;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'organization_uuid',
        'transaction_uuid',
        'event_uuid',
        'event_location_uuid',
        'event_ticket_uuid',
        'venue_seat_uuid',
        'ticket_number',
        'col',
        'row',
        'status',
        'attendee_name',
        'attendee_email',
        'attendee_contact',
        'qr_code',
        'visit_policy',
        'valid_until',
        'used_at',
        'transferred_at',
        'transferred_to',
        'transfer_count',
        'transferred_by',
        'is_downloaded',
        'price',
        'discount',
        'type',
        'other_info',
        'remarks',
        'created_by',
        'updated_by',
        'deleted_at',
    ];

    protected $casts = [
        'valid_until' => 'datetime',
        'used_at' => 'datetime',
        'transferred_at' => 'datetime',
        'transfer_count' => 'integer',
        'is_downloaded' => 'boolean',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'other_info' => 'json',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'user_uuid',
        'organization_uuid',
        'transaction_uuid',
        'event_uuid',
        'event_ticket_uuid',
        'venue_seat_uuid',
        'ticket_number',
        'col',
        'row',
        'status',
        'attendee_name',
        'attendee_email',
        'attendee_contact',
        'qr_code',
        'visit_policy',
        'valid_until',
        'used_at',
        'transferred_at',
        'transferred_to',
        'transferred_by',
        'transfer_count',
        'is_downloaded',
        'price',
        'discount',
        'other_info',
        'remarks',
        'created_by',
        'updated_by',
        'deleted_at',
    ];

    const TYPES = [
        'PAID' => 'paid',
        'PAID_NR' => 'paid-nr',
        'COMPLEMENTARY' => 'complementary',
        'UPGRADE' => 'upgrade',
        'DOWNGRADE' => 'downgrade',
        'PAID_TO_MERCHANT' => 'paid-to-merchant',
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
                $q->where('attendee_name', 'LIKE', "%$qKeyword%")
                    ->orWhere('attendee_email', 'LIKE', "%$qKeyword%")
                    ->orWhere('status', 'LIKE', "%$qKeyword%")
                    ->orWhere('qr_code', 'LIKE', "%$qKeyword%")
                    ->orWhere('ticket_number', 'LIKE', "%$qKeyword%")
                    ->orWhereHas('event', function ($q) use ($qKeyword) {
                        $q->where('event_name', 'LIKE', "%$qKeyword%")
                            ->orWhere('event_description', 'LIKE', "%$qKeyword%")
                            ->orWhere('event_type', 'LIKE', "%$qKeyword%");
                    });
            });
        }

        if (isset($filters['user_uuid'])) {
            $query = $query->where('user_uuid', $filters['user_uuid']);
        }

        if (isset($filters['transaction_uuid'])) {
            $query = $query->where('transaction_uuid', $filters['transaction_uuid']);
        }

        if (isset($filters['event_uuid'])) {
            $query = $query->where('event_uuid', $filters['event_uuid']);
        }

        if (isset($filters['event_ticket_uuid'])) {
            $query = $query->where('event_ticket_uuid', $filters['event_ticket_uuid']);
        }

        if (isset($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (isset($filters['tab'])) {
            $query = match ($filters['tab']) {
                'upcoming' => $query->whereIn('status', [
                    GeneralConstants::TICKET_STATUSES['PENDING'],
                    GeneralConstants::TICKET_STATUSES['ACTIVE'],
                ])->scheduleNotPastDue(),
                'past' => $query->where(function (Builder $tabQuery) {
                    $tabQuery->whereIn('status', [
                        GeneralConstants::TICKET_STATUSES['USED'],
                        GeneralConstants::TICKET_STATUSES['EXPIRED'],
                    ])->orWhere(function (Builder $activePastQuery) {
                        $activePastQuery->whereIn('status', [
                            GeneralConstants::TICKET_STATUSES['PENDING'],
                            GeneralConstants::TICKET_STATUSES['ACTIVE'],
                        ])->schedulePastDue();
                    });
                }),
                'transferred' => $query->where('status', GeneralConstants::TICKET_STATUSES['TRANSFERRED']),
                default => $query,
            };
        }

        if (isset($filters['is_used'])) {
            if ($filters['is_used']) {
                $query = $query->whereNotNull('used_at');
            } else {
                $query = $query->whereNull('used_at');
            }
        }

        return $query;
    }

    /**
     * SQL expression for schedule date + end time, compatible with MySQL and SQLite.
     */
    public static function scheduleEndTimestampSql(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "datetime(COALESCE(schedules.date_to, schedules.date_from) || ' ' || schedule_times.time_end)";
        }

        return 'TIMESTAMP(COALESCE(schedules.date_to, schedules.date_from), schedule_times.time_end)';
    }

    /**
     * Ticket visit date or event schedule has ended (even if status is still active/unused).
     * Visit-date tickets stay valid through the end of the visit day (11:59 PM Manila).
     */
    public function scopeSchedulePastDue(Builder $query): Builder
    {
        $now = Carbon::now('Asia/Manila');
        $today = $now->toDateString();

        return $query->where(function (Builder $q) use ($now, $today) {
            $q->where(function (Builder $visitPast) use ($today) {
                $visitPast->where(function (Builder $inner) use ($today) {
                    $inner->whereNotNull('valid_until')
                        ->whereRaw('DATE(valid_until) < ?', [$today]);
                })->orWhereExists(function ($sub) use ($today) {
                    $sub->selectRaw('1')
                        ->from('transaction_orders')
                        ->whereColumn('transaction_orders.transaction_uuid', 'tickets.transaction_uuid')
                        ->whereColumn('transaction_orders.event_ticket_uuid', 'tickets.event_ticket_uuid')
                        ->whereNotNull('transaction_orders.valid_until')
                        ->whereRaw('DATE(transaction_orders.valid_until) < ?', [$today]);
                });
            })->orWhere(function (Builder $schedulePast) use ($now, $today) {
                $schedulePast
                    ->whereNull('tickets.valid_until')
                    ->whereNotExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('transaction_orders')
                            ->whereColumn('transaction_orders.transaction_uuid', 'tickets.transaction_uuid')
                            ->whereColumn('transaction_orders.event_ticket_uuid', 'tickets.event_ticket_uuid')
                            ->whereNotNull('transaction_orders.valid_until');
                    })
                    ->whereExists(function ($sub) use ($now, $today) {
                        $sub->selectRaw('1')
                            ->from('transactions')
                            ->join('schedules', 'schedules.uuid', '=', 'transactions.schedule_uuid')
                            ->leftJoin('schedule_times', 'schedule_times.uuid', '=', 'transactions.schedule_time_uuid')
                            ->whereColumn('transactions.uuid', 'tickets.transaction_uuid')
                            ->where(function ($when) use ($now, $today) {
                                $when->where(function ($withTime) use ($now) {
                                    $withTime->whereNotNull('schedule_times.time_end')
                                        ->whereRaw(
                                            self::scheduleEndTimestampSql() . ' < ?',
                                            [$now]
                                        );
                                })->orWhere(function ($dateOnly) use ($today) {
                                    $dateOnly->where(function ($timeMissing) {
                                        $timeMissing->whereNull('schedule_times.time_end')
                                            ->orWhereNull('transactions.schedule_time_uuid');
                                    })->whereRaw(
                                        'DATE(COALESCE(schedules.date_to, schedules.date_from)) < ?',
                                        [$today]
                                    );
                                });
                            });
                    });
            });
        });
    }

    public function scopeScheduleNotPastDue(Builder $query): Builder
    {
        $now = Carbon::now('Asia/Manila');
        $today = $now->toDateString();

        return $query->where(function (Builder $q) use ($now, $today) {
            $q->where(function (Builder $visitCurrent) use ($today) {
                $visitCurrent->where(function (Builder $inner) use ($today) {
                    $inner->whereNotNull('valid_until')
                        ->whereRaw('DATE(valid_until) >= ?', [$today]);
                })->orWhereExists(function ($sub) use ($today) {
                    $sub->selectRaw('1')
                        ->from('transaction_orders')
                        ->whereColumn('transaction_orders.transaction_uuid', 'tickets.transaction_uuid')
                        ->whereColumn('transaction_orders.event_ticket_uuid', 'tickets.event_ticket_uuid')
                        ->whereNotNull('transaction_orders.valid_until')
                        ->whereRaw('DATE(transaction_orders.valid_until) >= ?', [$today]);
                });
            })->orWhere(function (Builder $scheduleCurrent) use ($now, $today) {
                $scheduleCurrent
                    ->whereNull('tickets.valid_until')
                    ->whereNotExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('transaction_orders')
                            ->whereColumn('transaction_orders.transaction_uuid', 'tickets.transaction_uuid')
                            ->whereColumn('transaction_orders.event_ticket_uuid', 'tickets.event_ticket_uuid')
                            ->whereNotNull('transaction_orders.valid_until');
                    })
                    ->where(function (Builder $inner) use ($now, $today) {
                        $inner->whereDoesntHave('transaction', function (Builder $tx) {
                            $tx->whereNotNull('schedule_uuid');
                        })->orWhereNotExists(function ($sub) use ($now, $today) {
                            $sub->selectRaw('1')
                                ->from('transactions')
                                ->join('schedules', 'schedules.uuid', '=', 'transactions.schedule_uuid')
                                ->leftJoin('schedule_times', 'schedule_times.uuid', '=', 'transactions.schedule_time_uuid')
                                ->whereColumn('transactions.uuid', 'tickets.transaction_uuid')
                                ->where(function ($when) use ($now, $today) {
                                    $when->where(function ($withTime) use ($now) {
                                        $withTime->whereNotNull('schedule_times.time_end')
                                            ->whereRaw(
                                                self::scheduleEndTimestampSql() . ' < ?',
                                                [$now]
                                            );
                                    })->orWhere(function ($dateOnly) use ($today) {
                                        $dateOnly->where(function ($timeMissing) {
                                            $timeMissing->whereNull('schedule_times.time_end')
                                                ->orWhereNull('transactions.schedule_time_uuid');
                                        })->whereRaw(
                                            'DATE(COALESCE(schedules.date_to, schedules.date_from)) < ?',
                                            [$today]
                                        );
                                    });
                                });
                        });
                    });
            });
        });
    }

    public function isSchedulePastDue(): bool
    {
        if ($this->hasVisitDate()) {
            return $this->isVisitDatePastDue();
        }

        $now = Carbon::now('Asia/Manila');
        $transaction = $this->transaction;
        if (!$transaction?->schedule_uuid) {
            return false;
        }

        if (!$transaction->relationLoaded('schedule')) {
            $transaction->load(['schedule', 'scheduleTime']);
        }

        $schedule = $transaction->schedule;
        if (!$schedule) {
            return false;
        }

        $eventDate = $schedule->date_to ?? $schedule->date_from;
        if (!$eventDate) {
            return false;
        }

        $scheduleTime = $transaction->scheduleTime;
        if ($scheduleTime?->time_end) {
            return Carbon::parse(
                $eventDate->format('Y-m-d') . ' ' . $scheduleTime->time_end,
                'Asia/Manila',
            )->lt($now);
        }

        return $eventDate->copy()->timezone('Asia/Manila')->endOfDay()->lt($now);
    }

    public function hasVisitDate(): bool
    {
        if ($this->valid_until) {
            return true;
        }

        $transaction = $this->transaction;
        if (!$transaction) {
            return false;
        }

        if (!$transaction->relationLoaded('transactionOrders')) {
            $transaction->load('transactionOrders');
        }

        return $transaction->transactionOrders
            ->contains(fn (TransactionOrder $order) => $order->event_ticket_uuid === $this->event_ticket_uuid
                && $order->valid_until !== null);
    }

    public function isVisitDatePastDue(): bool
    {
        $visitDate = $this->resolveDateOfVisit();
        if (!$visitDate) {
            return false;
        }

        $today = Carbon::now('Asia/Manila')->toDateString();

        return $visitDate->copy()->timezone('Asia/Manila')->toDateString() < $today;
    }

    public function resolveDateOfVisit(): ?Carbon
    {
        if ($this->valid_until) {
            return $this->valid_until;
        }

        $transaction = $this->transaction;
        if (!$transaction) {
            return null;
        }

        if (!$transaction->relationLoaded('transactionOrders')) {
            $transaction->load('transactionOrders');
        }

        $order = $transaction->transactionOrders
            ->first(fn (TransactionOrder $order) => $order->event_ticket_uuid === $this->event_ticket_uuid);

        if ($order?->valid_until) {
            return $order->valid_until;
        }

        if (!$transaction->relationLoaded('schedule')) {
            $transaction->load('schedule');
        }

        return $transaction->schedule?->date_from;
    }

    public function scopeOwner(Builder $query): Builder
    {
        return $query->where('user_uuid', auth('api')->user()->uuid);
    }

    public function scopeByOrganization(Builder $query): Builder
    {
        if (auth('admin')->user() && !auth('admin')->user()->role->is_admin) {
            return $query->where('organization_uuid', auth('admin')->user()->organization_uuid);
        }
        return $query;
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function eventTicket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'event_ticket_uuid', 'uuid');
    }

    public function eventLocation(): BelongsTo
    {
        return $this->belongsTo(EventLocation::class, 'event_location_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function ticketSeat(): HasOne
    {
        return $this->hasOne(TicketSeat::class);
    }

    public function venueSeat(): BelongsTo
    {
        return $this->belongsTo(VenueSeat::class, 'venue_seat_uuid', 'uuid');
    }

    public function transferredToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_to', 'uuid');
    }

    public function transferredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by', 'uuid');
    }

    public function scopeOwnedBy(Builder $query, string $userUuid): Builder
    {
        return $query->where('user_uuid', $userUuid);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GeneralConstants::TICKET_STATUSES['ACTIVE']);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function scopeNotInactive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [GeneralConstants::TICKET_STATUSES['INACTIVE'], GeneralConstants::TICKET_STATUSES['CANCELLED']]);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(TicketCoupon::class, 'ticket_uuid', 'uuid');
    }
}
