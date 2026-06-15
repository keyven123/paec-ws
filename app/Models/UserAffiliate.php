<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAffiliate extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $table = 'user_affiliates';

    protected $fillable = [
        'user_uuid',
        'affiliate_status',
        'affiliate_code',
        'affiliate_applied_at',
        'affiliate_approved_at',
        'affiliate_suspend_reason',
        'affiliate_suspended_at',
        'affiliate_bank_name',
        'affiliate_bank_branch',
        'affiliate_bank_account_name',
        'affiliate_bank_account_number',
        'affiliate_bank_tin',
    ];

    protected function casts(): array
    {
        return [
            'affiliate_applied_at' => 'datetime',
            'affiliate_approved_at' => 'datetime',
            'affiliate_suspended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
