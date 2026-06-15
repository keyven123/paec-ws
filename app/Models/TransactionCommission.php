<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Denormalized commissions / fees ledger.
 *
 * One row per money-moving event (paid transaction, chargeback, cancellation,
 * refund, …). Stores the gross amount, the platform's cut, the agent's cut,
 * and the payment-gateway fee at the moment of recording so that revenue,
 * gateway-balance and net-payable reports do not need to recompute against
 * potentially-mutating rate datasets.
 */
class TransactionCommission extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'accountable_id',
        'accountable_type',
        'transaction_uuid',
        'event_uuid',
        'organization_uuid',
        'agent_uuid',
        'gross_amount',
        'net_amount',
        'ticketoc_commission_percent',
        'ticketoc_commission',
        'ticketoc_net_commission',
        'agent_commission_percent',
        'agent_commission',
        'payment_provider',
        'payment_method',
        'payment_id',
        'payment_gateway_commission_percent',
        'payment_gateway_fixed_fee',
        'payment_gateway_commission',
        'currency',
        'transaction_type',
        'date_paid',
        'metadata',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'ticketoc_commission_percent' => 'decimal:2',
        'ticketoc_commission' => 'decimal:2',
        'ticketoc_net_commission' => 'decimal:2',
        'agent_commission_percent' => 'decimal:2',
        'agent_commission' => 'decimal:2',
        'payment_gateway_commission_percent' => 'decimal:3',
        'payment_gateway_fixed_fee' => 'decimal:2',
        'payment_gateway_commission' => 'decimal:2',
        'date_paid' => 'datetime',
        'metadata' => 'array',
    ];

    public const TYPE = [
        'TRANSACTION' => 'transaction',
        'CHARGEBACK' => 'chargeback',
        'CANCELLED' => 'cancelled',
        'REFUND' => 'refund',
    ];

    public const PROVIDER = [
        'PAYMONGO' => 'paymongo',
        'PAYPAL' => 'paypal',
    ];

    public function accountable(): MorphTo
    {
        return $this->morphTo();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_uuid', 'uuid');
    }
}
