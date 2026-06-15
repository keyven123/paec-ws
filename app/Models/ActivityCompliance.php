<?php

namespace App\Models;

use App\Constants\GeneralConstants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ActivityCompliance extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const AMOUNT_TYPE = [
        'PERCENTAGE' => 'percentage',
        'FIXED' => 'fixed',
    ];

    public const AUDIT_ATTRIBUTES = [
        'label',
        'percentage',
        'fixed_amount',
        'amount_type',
        'status',
    ];

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'activityable_id',
        'activityable_type',
        'label',
        'percentage',
        'fixed_amount',
        'amount_type',
        'status',
        'updated_by_uuid',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
    ];

    public function activityable(): MorphTo
    {
        return $this->morphTo();
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ActivityComplianceHistory::class, 'activity_compliance_uuid', 'uuid');
    }

    public function transactionCompliances(): HasMany
    {
        return $this->hasMany(TransactionCompliance::class, 'activity_compliance_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_uuid', 'uuid');
    }

    public function isActive(): bool
    {
        return $this->status === GeneralConstants::GENERAL_STATUSES['ACTIVE'];
    }

    public function auditSnapshot(): array
    {
        return [
            'label' => $this->label,
            'percentage' => (float) $this->percentage,
            'fixed_amount' => $this->fixed_amount !== null ? (float) $this->fixed_amount : null,
            'amount_type' => $this->amount_type,
            'status' => $this->status,
        ];
    }
}
