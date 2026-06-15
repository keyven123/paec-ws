<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TempTransactionOrder extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'temp_transaction_uuid',
        'event_ticket_uuid',
        'quantity',
        'price',
        'markup_type',
        'markup_value',
        'markup',
        'markup_discount',
        'discount',
        'total_amount',
        'valid_until',
        'seats',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'price' => 'decimal:2',
        'markup_value' => 'decimal:2',
        'markup' => 'decimal:2',
        'markup_discount' => 'decimal:2',
        'quantity' => 'integer',
        'seats' => 'array',
        'discount' => 'decimal:2',
        'valid_until' => 'datetime',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'user_uuid',
        'temp_transaction_uuid',
        'event_ticket_uuid',
        'quantity',
        'price',
        'markup_type',
        'markup_value',
        'markup',
        'markup_discount',
        'discount',
        'total_amount',
        'valid_until',
        'seats',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function tempTransaction(): BelongsTo
    {
        return $this->belongsTo(TempTransaction::class, 'temp_transaction_uuid', 'uuid');
    }

    public function eventTicket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'event_ticket_uuid', 'uuid');
    }
}
