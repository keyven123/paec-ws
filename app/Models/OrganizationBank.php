<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationBank extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationBankFactory> */
    use HasFactory;
    use HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const ACCOUNT_TYPE_SAVINGS = 'savings';
    public const ACCOUNT_TYPE_CURRENT = 'current';
    public const ACCOUNT_TYPE_E_WALLET = 'e_wallet';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    public const ACCOUNT_TYPES = [
        self::ACCOUNT_TYPE_SAVINGS,
        self::ACCOUNT_TYPE_CURRENT,
        self::ACCOUNT_TYPE_E_WALLET,
    ];

    public $incrementing = false;
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'organization_uuid',
        'account_type',
        'bank_name',
        'bank_branch',
        'bank_address',
        'bank_account_name',
        'bank_account_number',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }
}
