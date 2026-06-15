<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReminderLog extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    public const TYPE_7_DAYS = '7d';

    public const TYPE_48_HOURS = '48h';

    public const TYPE_12_HOURS = '12h';

    public const TYPES = [
        self::TYPE_7_DAYS,
        self::TYPE_48_HOURS,
        self::TYPE_12_HOURS,
    ];

    protected $fillable = [
        'transaction_uuid',
        'user_uuid',
        'event_uuid',
        'schedule_uuid',
        'schedule_time_uuid',
        'group_key',
        'reminder_type',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }
}
