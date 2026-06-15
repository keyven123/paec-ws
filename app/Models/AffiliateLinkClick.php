<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateLinkClick extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'partner_user_uuid',
        'ref_code',
        'path',
        'ip_address',
        'user_agent',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_uuid', 'uuid');
    }
}
