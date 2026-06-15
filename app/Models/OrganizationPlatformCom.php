<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPlatformCom extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $table = 'organization_platform_coms';

    protected $fillable = [
        'organization_uuid',
        'previous_coms',
        'current_coms',
        'created_by',
    ];

    const DATA = [
        'organization_uuid',
        'previous_coms',
        'current_coms',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'previous_coms' => 'decimal:2',
            'current_coms' => 'decimal:2',
        ];
    }

    /**
     * @param Builder $query
     * @param array|null $filters
     * @return Builder
     */
    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (array_key_exists('organization_uuid', $filters ?? [])) {
            $organizationUuid = $filters['organization_uuid'];
            if ($organizationUuid === null || $organizationUuid === '') {
                $query->whereNull('organization_uuid');
            } else {
                $query->where('organization_uuid', $organizationUuid);
            }
        }

        return $query;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'uuid');
    }
}
