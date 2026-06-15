<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateConversion extends Model
{
    use HasUuids;

    public const ENTRY_TYPE_CREDIT = 'credit';

    public const ENTRY_TYPE_REVERSAL = 'reversal';

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'partner_user_uuid',
        'transaction_uuid',
        'entry_type',
        'ticket_uuid',
        'event_uuid',
        'order_total',
        'commission_percent',
        'commission_amount',
    ];

    protected function casts(): array
    {
        return [
            'order_total' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_uuid', 'uuid');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_uuid', 'uuid');
    }
}
