<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionCompliance extends Model
{
    use HasFactory;
    use HasUuids;

    public const APPLIES_TO_MERCHANDISE = 'merchandise';

    public const APPLIES_TO_MARKUP = 'markup';

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'transaction_uuid',
        'activity_compliance_uuid',
        'percentage',
        'amount',
        'applies_to',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid', 'uuid');
    }

    public function activityCompliance(): BelongsTo
    {
        return $this->belongsTo(ActivityCompliance::class, 'activity_compliance_uuid', 'uuid');
    }
}
