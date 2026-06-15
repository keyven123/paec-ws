<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class VenueInquiry extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const STATUSES = [
        'NEW' => 'new',
        'IN_DISCUSSION' => 'in_discussion',
        'SITE_VISIT_SCHEDULED' => 'site_visit_scheduled',
        'PROPOSAL_SENT' => 'proposal_sent',
        'ACCEPTED' => 'accepted',
        'DEPOSIT_REQUESTED' => 'deposit_requested',
        'DEPOSIT_PAID' => 'deposit_paid',
        'BALANCE_DUE' => 'balance_due',
        'FULLY_PAID' => 'fully_paid',
        'COMPLETED' => 'completed',
        'CANCELLED' => 'cancelled',
    ];

    /** Statuses that represent an open / competing inquiry on a date. */
    public const OPEN_STATUSES = [
        self::STATUSES['NEW'],
        self::STATUSES['IN_DISCUSSION'],
        self::STATUSES['SITE_VISIT_SCHEDULED'],
        self::STATUSES['PROPOSAL_SENT'],
        self::STATUSES['ACCEPTED'],
        self::STATUSES['DEPOSIT_REQUESTED'],
    ];

    /**
     * Merchant-facing APIs hide customer email/phone while an inquiry is still
     * open or has been cancelled. Contact details are revealed after deposit is paid.
     */
    public const MERCHANT_CONTACT_HIDDEN_STATUSES = [
        ...self::OPEN_STATUSES,
        self::STATUSES['CANCELLED'],
    ];

    /** Soft-hold: date is reserved but not fully booked. */
    public const SOFT_HOLD_STATUSES = [
        self::STATUSES['DEPOSIT_PAID'],
        self::STATUSES['BALANCE_DUE'],
    ];

    /** Hard block: date is fully booked. */
    public const HARD_BLOCK_STATUSES = [
        self::STATUSES['FULLY_PAID'],
        self::STATUSES['COMPLETED'],
    ];

    public const PAYMENT_PHASE_DEPOSIT = 'deposit';
    public const PAYMENT_PHASE_BALANCE = 'balance';

    public const SITE_VISIT_YES = 'yes';
    public const SITE_VISIT_NO = 'no';

    public const SITE_VISITS = [
        self::SITE_VISIT_YES,
        self::SITE_VISIT_NO,
    ];

    protected $fillable = [
        'venue_listing_uuid',
        'user_uuid',
        'full_name',
        'email',
        'phone',
        'event_type',
        'event_date',
        'guest_count',
        'site_visit',
        'visit_scheduled_date',
        'visit_scheduled_time',
        'message',
        'status',
        'approved_amount',
        'approved_due_date',
        'proposal_amount',
        'proposal_valid_until',
        'proposal_upload_uuid',
        'proposal_sent_at',
        'accepted_at',
        'deposit_amount',
        'deposit_due_date',
        'deposit_paid_at',
        'balance_amount',
        'additional_charges',
        'balance_due_date',
        'fully_paid_at',
        'completed_at',
    ];

    protected $casts = [
        'event_date' => 'date',
        'visit_scheduled_date' => 'date',
        'approved_due_date' => 'date',
        'proposal_valid_until' => 'date',
        'deposit_due_date' => 'date',
        'balance_due_date' => 'date',
        'approved_amount' => 'decimal:2',
        'proposal_amount' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'additional_charges' => 'decimal:2',
        'proposal_sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'deposit_paid_at' => 'datetime',
        'fully_paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function venueListing(): BelongsTo
    {
        return $this->belongsTo(VenueListing::class, 'venue_listing_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function proposalUpload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'proposal_upload_uuid', 'uuid');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(
            Transaction::class,
            'transactionable',
            'transactionable_type',
            'transactionable_uuid',
            'uuid',
        );
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUSES['NEW'] => 'New',
            self::STATUSES['IN_DISCUSSION'] => 'In Discussion',
            self::STATUSES['SITE_VISIT_SCHEDULED'] => 'Site Visit Scheduled',
            self::STATUSES['PROPOSAL_SENT'] => 'Proposal Sent',
            self::STATUSES['ACCEPTED'] => 'Accepted',
            self::STATUSES['DEPOSIT_REQUESTED'] => 'Deposit Requested',
            self::STATUSES['DEPOSIT_PAID'] => 'Deposit Paid',
            self::STATUSES['BALANCE_DUE'] => 'Balance Due',
            self::STATUSES['FULLY_PAID'] => 'Fully Paid',
            self::STATUSES['COMPLETED'] => 'Completed',
            self::STATUSES['CANCELLED'] => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function customerStatusLabel(string $status): string
    {
        return match ($status) {
            self::STATUSES['NEW'] => 'Inquiry Submitted',
            self::STATUSES['IN_DISCUSSION'] => 'Discussion with Venue',
            self::STATUSES['SITE_VISIT_SCHEDULED'] => 'Site Visit Scheduled',
            self::STATUSES['PROPOSAL_SENT'] => 'Proposal Received',
            self::STATUSES['ACCEPTED'] => 'Accept Proposal',
            self::STATUSES['DEPOSIT_REQUESTED'] => 'Deposit Requested',
            self::STATUSES['DEPOSIT_PAID'] => 'Deposit Paid',
            self::STATUSES['BALANCE_DUE'] => 'Final Billing Received',
            self::STATUSES['FULLY_PAID'] => 'Payment Completed',
            self::STATUSES['COMPLETED'] => 'Event Completed',
            self::STATUSES['CANCELLED'] => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function merchantCanViewContactDetails(): bool
    {
        return ! in_array($this->status, self::MERCHANT_CONTACT_HIDDEN_STATUSES, true);
    }
}
