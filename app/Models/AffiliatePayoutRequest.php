<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePayoutRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'user_uuid',
        'amount_requested',
        'currency',
        'status',
        'admin_notes',
        'processed_at',
        'processed_by_uuid',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
