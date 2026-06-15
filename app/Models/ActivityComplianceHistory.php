<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityComplianceHistory extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'activity_compliance_uuid',
        'previous_value',
        'current_value',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'previous_value' => 'array',
        'current_value' => 'array',
        'created_at' => 'datetime',
    ];

    public function activityCompliance(): BelongsTo
    {
        return $this->belongsTo(ActivityCompliance::class, 'activity_compliance_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'uuid');
    }
}
