<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantPayoutRequest extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'organization_uuid',
        'organization_bank_uuid',
        'event_uuid',
        'amount_requested',
        'currency',
        'status',
        'merchant_note',
        'admin_notes',
        'void_at',
        'void_by_uuid',
        'processed_at',
        'processed_by_uuid',
        'requested_by_admin_uuid',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'decimal:2',
            'processed_at' => 'datetime',
            'void_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function organizationBank(): BelongsTo
    {
        return $this->belongsTo(OrganizationBank::class, 'organization_bank_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function voidBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'void_by_uuid', 'uuid');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'processed_by_uuid', 'uuid');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_uuid', 'uuid');
    }
}
