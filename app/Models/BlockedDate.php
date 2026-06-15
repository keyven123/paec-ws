<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlockedDate extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'blockable_type',
        'blockable_uuid',
        'blocked_date',
        'reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'blocked_date' => 'date',
    ];

    // Define the DATA constant for repository filtering
    const DATA = [
        'blockable_type',
        'blockable_uuid',
        'blocked_date',
        'reason',
        'created_by',
        'updated_by',
    ];

    /**
     * Scope for filtering records.
     */
    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['blockable_type'])) {
            $query->where('blockable_type', $filters['blockable_type']);
        }

        if (isset($filters['blockable_uuid'])) {
            $query->where('blockable_uuid', $filters['blockable_uuid']);
        }

        if (isset($filters['q'])) {
            $q = $filters['q'];
            $query->where('reason', 'LIKE', "%$q%");
        }

        return $query;
    }

    /**
     * The parent model (Event, VenueListing, ...) this date is blocked for.
     */
    public function blockable(): MorphTo
    {
        return $this->morphTo('blockable', 'blockable_type', 'blockable_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }
}
