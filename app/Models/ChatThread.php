<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatThread extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_MERCHANT = 'merchant';

    protected $fillable = [
        'venue_inquiry_uuid',
        'venue_listing_uuid',
        'organization_uuid',
        'customer_uuid',
        'last_message_preview',
        'last_message_at',
        'customer_last_read_at',
        'merchant_last_read_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'customer_last_read_at' => 'datetime',
        'merchant_last_read_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_thread_uuid', 'uuid');
    }

    public function venueInquiry(): BelongsTo
    {
        return $this->belongsTo(VenueInquiry::class, 'venue_inquiry_uuid', 'uuid');
    }

    public function venueListing(): BelongsTo
    {
        return $this->belongsTo(VenueListing::class, 'venue_listing_uuid', 'uuid');
    }

    /**
     * Number of messages the given side has not yet read.
     */
    public function unreadCountFor(string $side): int
    {
        $lastReadAt = $side === self::SENDER_CUSTOMER
            ? $this->customer_last_read_at
            : $this->merchant_last_read_at;

        $otherSide = $side === self::SENDER_CUSTOMER
            ? self::SENDER_MERCHANT
            : self::SENDER_CUSTOMER;

        return $this->messages()
            ->where('sender_type', $otherSide)
            ->when($lastReadAt, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->count();
    }
}
